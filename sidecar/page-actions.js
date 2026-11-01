/**
 * Message actions run directly against WhatsApp Web's own modules.
 *
 * whatsapp-web.js routes react / edit / forward / delete / download through
 * getMessageById(), whose message serializer breaks on current WhatsApp Web
 * builds (it throws `r`), and its forward helper calls a module that is now
 * only loaded on demand. The actions themselves are single module calls, so
 * we make them ourselves on the raw message model.
 *
 * pageMessageAction() runs inside the browser page and must be
 * self-contained — Puppeteer stringifies it.
 */

/**
 * @param {'react'|'edit'|'forward'|'delete'|'download'} action
 * @param {string} msgId   serialized message ID
 * @param {object} args    action arguments
 * @returns {Promise<object>}  `{ ok: true, ... }` or `{ error, http }`
 */
async function pageMessageAction(action, msgId, args) {
  const req = (name) => {
    try {
      return window.require(name);
    } catch (_) {
      return undefined;
    }
  };
  const fail = (error, http) => ({ error, http });

  const C = req('WAWebCollections');
  if (!C || !C.Msg) return fail('the WhatsApp Web store is unavailable', 503);

  // Current builds key messages as `…_out` / `…_in`; IDs stored before that
  // change don't have the suffix. Try the ID as given, then with each suffix.
  const candidates = [msgId];
  if (!/_(out|in)$/.test(msgId)) candidates.push(`${msgId}_out`, `${msgId}_in`);

  let msg = null;
  for (const id of candidates) {
    try { msg = C.Msg.get(id); } catch (_) { msg = null; }
    if (msg) break;
  }
  for (const id of candidates) {
    if (msg) break;
    try {
      const found = await C.Msg.getMessagesById([id]);
      msg = (found && found.messages && found.messages[0]) || null;
    } catch (_) {
      msg = null;
    }
  }
  if (!msg) return fail('message not found', 404);

  const capability = req('WAWebMsgActionCapability') || {};
  const can = (name) => {
    try {
      return typeof capability[name] === 'function' ? !!capability[name](msg) : null;
    } catch (_) {
      return null;
    }
  };

  const findChat = async (chatId) => {
    let id = String(chatId || '');
    if (!id.includes('@')) id = `${id.replace(/\D+/g, '')}@c.us`;
    if (window.WWebJS && typeof window.WWebJS.getChat === 'function') {
      try {
        const chat = await window.WWebJS.getChat(id, { getAsModel: false });
        if (chat) return chat;
      } catch (_) { /* fall through */ }
    }
    try {
      const chat = C.Chat.get(id);
      if (chat) return chat;
    } catch (_) { /* fall through */ }
    try {
      const wid = req('WAWebWidFactory').createWid(id);
      const lid = req('WAWebApiContact').getCurrentLid(wid);
      return (lid && C.Chat.get(lid)) || (await C.Chat.find(wid));
    } catch (_) {
      return null;
    }
  };

  switch (action) {
    case 'react': {
      const send = req('WAWebSendReactionMsgAction');
      if (!send || typeof send.sendReactionToMsg !== 'function') return fail('reactions are unavailable in this WhatsApp Web build', 501);
      await send.sendReactionToMsg(msg, String(args.emoji || ''));
      return { ok: true };
    }

    case 'edit': {
      if (!(msg.id && msg.id.fromMe)) return fail('only your own messages can be edited', 409);
      const allowed = [can('canEditText'), can('canEditCaption')];
      if (allowed.every((v) => v === false)) return fail('this message can no longer be edited (WhatsApp allows about 15 minutes)', 409);
      const edit = req('WAWebSendMessageEditAction');
      if (!edit || typeof edit.sendMessageEdit !== 'function') return fail('editing is unavailable in this WhatsApp Web build', 501);
      await edit.sendMessageEdit(msg, String(args.body || ''), {});
      return { ok: true };
    }

    case 'forward': {
      const chat = await findChat(args.to);
      if (!chat) return fail('destination chat not found', 404);
      let forward = req('WAWebChatForwardMessage');
      if (!forward || typeof forward.forwardMessages !== 'function') {
        // WhatsApp Web loads this module only when the forward dialog opens.
        const loader = req('WAWebForwardMessageFlowLoadable');
        if (loader && typeof loader.requireBundle === 'function') await loader.requireBundle();
        forward = req('WAWebChatForwardMessage');
      }
      if (!forward || typeof forward.forwardMessages !== 'function') return fail('forwarding is unavailable in this WhatsApp Web build', 501);
      await forward.forwardMessages({ chat, msgs: [msg], multicast: true, includeCaption: true, appendedText: undefined });
      return { ok: true };
    }

    case 'delete': {
      const remote = msg.id && msg.id.remote;
      let chat = null;
      try { chat = C.Chat.get(remote) || (await C.Chat.find(remote)); } catch (_) { chat = null; }
      if (!chat) return fail('chat not found', 404);
      const { Cmd } = req('WAWebCmd') || {};
      if (!Cmd) return fail('deleting is unavailable in this WhatsApp Web build', 501);
      if (args.everyone) {
        if (can('canSenderRevokeMsg') === false && can('canAdminRevokeMsg') === false) {
          return fail('this message can no longer be deleted for everyone', 409);
        }
        await Cmd.sendRevokeMsgs(chat, { list: [msg], type: 'message' }, { clearMedia: true });
      } else {
        await Cmd.sendDeleteMsgs(chat, { list: [msg], type: 'message' }, true);
      }
      return { ok: true };
    }

    case 'download': {
      const media = msg.mediaData;
      if (!media || media.mediaStage === 'REUPLOADING') return fail('no media for this message', 404);
      if (media.mediaStage !== 'RESOLVED' && typeof msg.downloadMedia === 'function') {
        await msg.downloadMedia({ downloadEvenIfExpensive: true, rmrReason: 1 });
      }
      if (String(media.mediaStage).includes('ERROR') || media.mediaStage === 'FETCHING') {
        return fail('media download failed (it may have expired on WhatsApp\'s servers)', 404);
      }
      const manager = req('WAWebDownloadManager');
      if (!manager || !manager.downloadManager) return fail('media download is unavailable in this WhatsApp Web build', 501);
      const qpl = { addAnnotations() { return this; }, addPoint() { return this; } };
      const buffer = await manager.downloadManager.downloadAndMaybeDecrypt({
        directPath: msg.directPath,
        encFilehash: msg.encFilehash,
        filehash: msg.filehash,
        mediaKey: msg.mediaKey,
        mediaKeyTimestamp: msg.mediaKeyTimestamp,
        type: msg.type,
        // Current builds validate this; without it they default to
        // application/octet-stream and reject image/video/audio downloads.
        mimetype: msg.mimetype,
        signal: new AbortController().signal,
        downloadQpl: qpl,
      });
      const bytes = new Uint8Array(buffer);
      let binary = '';
      for (let i = 0; i < bytes.length; i += 0x8000) {
        binary += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
      }
      return { ok: true, data: btoa(binary), mimetype: msg.mimetype || null, filename: msg.filename || null };
    }

    default:
      return fail(`unknown action: ${action}`, 400);
  }
}

/**
 * Run an action in the session's page. Throws `{ http }` errors the sidecar's
 * error handler turns into responses.
 *
 * @param {{ evaluate: Function }} page
 */
async function runMessageAction(page, action, msgId, args = {}) {
  if (!page || typeof page.evaluate !== 'function') {
    throw Object.assign(new Error('the browser page is unavailable'), { http: 503 });
  }

  const result = await page.evaluate(pageMessageAction, action, String(msgId || ''), args);
  if (!result || result.error) {
    throw Object.assign(new Error((result && result.error) || 'action failed'), { http: (result && result.http) || 500 });
  }

  return result;
}

module.exports = { runMessageAction, pageMessageAction };
