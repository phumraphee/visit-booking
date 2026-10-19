// Passkey (Face ID / Touch ID / ลายนิ้วมือ) — ใช้ร่วมกันระหว่างหลังบ้านและหน้าสแกน
// Safari ต้องเรียก navigator.credentials.* ทันทีในจังหวะที่ผู้ใช้แตะ → โหลด options (challenge) เตรียมไว้ก่อนเสมอ
(() => {
  'use strict';
  const enc = buf => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const dec = s => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), c => c.charCodeAt(0));
  const supported = !!(window.PublicKeyCredential && navigator.credentials && window.PublicKeyCredential.prototype && 'getPublicKey' in (window.AuthenticatorAttestationResponse?.prototype || {}));
  const store = { get: k => { try { return localStorage.getItem(k); } catch (_) { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (_) {} } };
  const deviceName = () => {
    const ua = navigator.userAgent;
    if (/iPhone/.test(ua)) return 'iPhone';
    if (/iPad/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) return 'iPad';
    if (/Android/.test(ua)) return 'Android';
    if (/Macintosh/.test(ua)) return 'Mac';
    if (/Windows/.test(ua)) return 'Windows';
    return 'Passkey';
  };

  // api(action, data) → Promise<json> (ของแต่ละหน้า)
  function create(api) {
    let loginOpts = null, loginAt = 0, regOpts = null, regAt = 0;
    const fresh = at => Date.now() - at < 240000;   // challenge อายุ 5 นาทีที่ server
    const prepLogin = () => api('passkey_login_options').then(j => { loginOpts = j.options; loginAt = Date.now(); }).catch(() => {});
    const prepRegister = () => api('passkey_register_options').then(j => { regOpts = j.options; regAt = Date.now(); }).catch(() => {});

    // ต้องเรียกจาก click handler โดยตรง (ห้าม await อย่างอื่นก่อน)
    async function login() {
      if (!loginOpts || !fresh(loginAt)) { await prepLogin(); throw new Error('retry'); }
      const o = loginOpts; loginOpts = null;
      const pk = { challenge: dec(o.challenge), rpId: o.rpId, userVerification: o.userVerification, timeout: o.timeout, allowCredentials: [] };
      let cred;
      try { cred = await navigator.credentials.get({ publicKey: pk }); }
      finally { prepLogin(); }
      const r = cred.response;
      const j = await api('passkey_login', { id: enc(cred.rawId), clientDataJSON: enc(r.clientDataJSON), authenticatorData: enc(r.authenticatorData), signature: enc(r.signature) });
      store.set('vb-pk', '1');
      return j;
    }

    async function register() {
      if (!regOpts || !fresh(regAt)) { await prepRegister(); throw new Error('retry'); }
      const o = regOpts; regOpts = null;
      const pk = { ...o, challenge: dec(o.challenge), user: { ...o.user, id: dec(o.user.id) }, excludeCredentials: o.excludeCredentials.map(c => ({ type: c.type, id: dec(c.id) })) };
      let cred;
      try { cred = await navigator.credentials.create({ publicKey: pk }); }
      finally { prepRegister(); }
      const r = cred.response;
      await api('passkey_register', { id: enc(cred.rawId), clientDataJSON: enc(r.clientDataJSON), authenticatorData: enc(r.getAuthenticatorData()), publicKey: enc(r.getPublicKey()), alg: r.getPublicKeyAlgorithm(), name: deviceName() });
      store.set('vb-pk', '1');
    }

    return { prepLogin, prepRegister, login, register };
  }

  // ข้อความผิดพลาดที่ผู้ใช้เข้าใจได้ (null = ผู้ใช้กดยกเลิกเอง ไม่ต้องแสดงอะไร)
  function errorText(x) {
    if (x.message === 'retry') return 'กรุณาแตะอีกครั้ง';
    if (x.name === 'NotAllowedError' || x.name === 'AbortError') return null;
    if (x.name === 'InvalidStateError') return 'เครื่องนี้ตั้ง passkey ไว้แล้ว';
    return (x.message || 'ไม่สำเร็จ') + (x.name && x.name !== 'Error' ? ` (${x.name})` : '');
  }

  window.Passkey = { supported, create, errorText, hasOnDevice: () => store.get('vb-pk') === '1', asked: () => store.get('vb-pk-asked') === '1', setAsked: () => store.set('vb-pk-asked', '1') };
})();
