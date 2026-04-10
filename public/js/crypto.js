/**
 * crypto.js — End-to-End Encryption (E2EE) module
 *
 * Uses the Web Crypto API (SubtleCrypto) — no external libraries.
 *
 * Encryption scheme:
 *   • RSA-OAEP (2048-bit, SHA-256) — asymmetric, used to wrap/unwrap the per-thread AES key.
 *     (2048-bit is used instead of 4096-bit for significantly faster key generation
 *      without materially sacrificing security for this use-case.)
 *   • AES-GCM (256-bit) — symmetric, used to encrypt every message.
 *
 * Key storage:
 *   • Private RSA key → IndexedDB (non-extractable CryptoKey object)
 *   • Public  RSA key → server (/api/user/public-key) as base64-encoded SPKI
 *   • Thread AES keys → in-memory Map for the session duration
 */
(function (global) {
    'use strict';

    const DB_NAME    = 'e2ee_keystore';
    const DB_VERSION = 1;
    const STORE_NAME = 'keys';
    const PRIV_KEY_ID = 'rsa_private_key';

    // ── Utility helpers ────────────────────────────────────────────────────────

    function bufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary  = '';
        for (let i = 0; i < bytes.byteLength; i++) binary += String.fromCharCode(bytes[i]);
        return btoa(binary);
    }

    function base64ToBuffer(b64) {
        const binary = atob(b64);
        const buffer = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) buffer[i] = binary.charCodeAt(i);
        return buffer.buffer;
    }

    // ── IndexedDB helpers ──────────────────────────────────────────────────────

    function openDB() {
        return new Promise((resolve, reject) => {
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = (e) => {
                e.target.result.createObjectStore(STORE_NAME);
            };
            req.onsuccess = (e) => resolve(e.target.result);
            req.onerror   = (e) => reject(e.target.error);
        });
    }

    function dbPut(db, key, value) {
        return new Promise((resolve, reject) => {
            const tx  = db.transaction(STORE_NAME, 'readwrite');
            const req = tx.objectStore(STORE_NAME).put(value, key);
            req.onsuccess = () => resolve();
            req.onerror   = (e) => reject(e.target.error);
        });
    }

    function dbGet(db, key) {
        return new Promise((resolve, reject) => {
            const tx  = db.transaction(STORE_NAME, 'readonly');
            const req = tx.objectStore(STORE_NAME).get(key);
            req.onsuccess = (e) => resolve(e.target.result);
            req.onerror   = (e) => reject(e.target.error);
        });
    }

    // ── RSA key pair ───────────────────────────────────────────────────────────

    /**
     * Generate an RSA-OAEP key pair.
     * @returns {Promise<{publicKey: CryptoKey, privateKey: CryptoKey}>}
     */
    async function generateKeyPair() {
        return crypto.subtle.generateKey(
            {
                name:           'RSA-OAEP',
                modulusLength:  2048,
                publicExponent: new Uint8Array([1, 0, 1]),
                hash:           'SHA-256',
            },
            false,          // private key is non-extractable
            ['wrapKey', 'unwrapKey']
        );
    }

    /**
     * Export a public key to base64-encoded SPKI format for server storage.
     * @param {CryptoKey} publicKey
     * @returns {Promise<string>}
     */
    async function exportPublicKey(publicKey) {
        const spki = await crypto.subtle.exportKey('spki', publicKey);
        return bufferToBase64(spki);
    }

    /**
     * Import a base64-encoded SPKI public key back to a CryptoKey.
     * @param {string} b64Spki
     * @returns {Promise<CryptoKey>}
     */
    async function importPublicKey(b64Spki) {
        const spki = base64ToBuffer(b64Spki);
        return crypto.subtle.importKey(
            'spki',
            spki,
            { name: 'RSA-OAEP', hash: 'SHA-256' },
            false,
            ['wrapKey']
        );
    }

    // ── AES thread key ─────────────────────────────────────────────────────────

    /**
     * Generate a fresh AES-256-GCM key for a support thread.
     * @returns {Promise<CryptoKey>}
     */
    async function generateThreadKey() {
        return crypto.subtle.generateKey(
            { name: 'AES-GCM', length: 256 },
            true,   // extractable so we can wrap it
            ['encrypt', 'decrypt']
        );
    }

    /**
     * Wrap (encrypt) a thread AES key with a recipient's RSA public key.
     * @param {CryptoKey} threadKey
     * @param {CryptoKey} recipientPublicKey
     * @returns {Promise<string>} base64-encoded wrapped key
     */
    async function encryptThreadKeyForUser(threadKey, recipientPublicKey) {
        const wrapped = await crypto.subtle.wrapKey(
            'raw',
            threadKey,
            recipientPublicKey,
            { name: 'RSA-OAEP' }
        );
        return bufferToBase64(wrapped);
    }

    /**
     * Unwrap a base64 wrapped AES key using the user's RSA private key.
     * @param {string} wrappedKeyB64
     * @param {CryptoKey} privateKey
     * @returns {Promise<CryptoKey>}
     */
    async function decryptThreadKey(wrappedKeyB64, privateKey) {
        const wrappedBuffer = base64ToBuffer(wrappedKeyB64);
        return crypto.subtle.unwrapKey(
            'raw',
            wrappedBuffer,
            privateKey,
            { name: 'RSA-OAEP' },
            { name: 'AES-GCM', length: 256 },
            false,          // non-extractable after unwrapping
            ['encrypt', 'decrypt']
        );
    }

    // ── AES-GCM message encryption ─────────────────────────────────────────────

    /**
     * Encrypt a plaintext string with the thread's AES-GCM key.
     * @param {string} plaintext
     * @param {CryptoKey} threadKey
     * @returns {Promise<{ciphertext: string, iv: string}>}  both base64-encoded
     */
    async function encryptMessage(plaintext, threadKey) {
        const iv        = crypto.getRandomValues(new Uint8Array(12));
        const encoded   = new TextEncoder().encode(plaintext);
        const encrypted = await crypto.subtle.encrypt(
            { name: 'AES-GCM', iv },
            threadKey,
            encoded
        );
        return {
            ciphertext: bufferToBase64(encrypted),
            iv:         bufferToBase64(iv),
        };
    }

    /**
     * Decrypt a base64 ciphertext back to a plaintext string.
     * @param {string} ciphertextB64
     * @param {string} ivB64
     * @param {CryptoKey} threadKey
     * @returns {Promise<string>}
     */
    async function decryptMessage(ciphertextB64, ivB64, threadKey) {
        const iv         = base64ToBuffer(ivB64);
        const ciphertext = base64ToBuffer(ciphertextB64);
        const decrypted  = await crypto.subtle.decrypt(
            { name: 'AES-GCM', iv: new Uint8Array(iv) },
            threadKey,
            ciphertext
        );
        return new TextDecoder().decode(decrypted);
    }

    // ── IndexedDB key persistence ──────────────────────────────────────────────

    /**
     * Save the RSA private key to IndexedDB.
     * @param {CryptoKey} privateKey
     */
    async function savePrivateKey(privateKey) {
        const db = await openDB();
        await dbPut(db, PRIV_KEY_ID, privateKey);
    }

    /**
     * Load the RSA private key from IndexedDB.
     * @returns {Promise<CryptoKey|null>}
     */
    async function loadPrivateKey() {
        try {
            const db  = await openDB();
            const key = await dbGet(db, PRIV_KEY_ID);
            return key || null;
        } catch (_) {
            return null;
        }
    }

    // ── In-memory thread key cache ─────────────────────────────────────────────

    const _threadKeyCache = new Map();  // threadId (number) → CryptoKey

    function cacheThreadKey(threadId, threadKey) {
        _threadKeyCache.set(threadId, threadKey);
    }

    function getCachedThreadKey(threadId) {
        return _threadKeyCache.get(threadId) || null;
    }

    function clearThreadKeyCache() {
        _threadKeyCache.clear();
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    global.E2EE = {
        generateKeyPair,
        exportPublicKey,
        importPublicKey,
        generateThreadKey,
        encryptThreadKeyForUser,
        decryptThreadKey,
        encryptMessage,
        decryptMessage,
        savePrivateKey,
        loadPrivateKey,
        cacheThreadKey,
        getCachedThreadKey,
        clearThreadKeyCache,
    };

})(window);
