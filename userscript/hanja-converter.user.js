// ==UserScript==
// @name         VPS-Backed Hangeul to Hanja Converter
// @namespace    hanja-lexicon
// @version      1.1.0
// @description  Replace Hangeul nouns with Hanja using Kiwi in the browser and a remote lexicon
// @match        *://*/*
// @run-at       document-idle
// @inject-into  content
// @grant        GM_xmlhttpRequest
// @grant        GM_registerMenuCommand
// @grant        GM_getValue
// @grant        GM_setValue
// @connect      *
// ==/UserScript==

(function () {
    'use strict';

    // Origin that serves this project's public/ directory. No trailing slash.
    const API_ORIGIN = 'https://example.com';
    const API_URL = API_ORIGIN + '/api/v1/hanja/batch';
    const KIWI_MANIFEST = API_ORIGIN + '/kiwi/manifest.json';
    const CACHE_KEY = 'hanjaLexiconCache.v2';
    const ENABLED_KEY = 'hanjaEnabled';
    const CHUNK_SIZE = 1000;
    const IDB_NAME = 'hanja-kiwi';
    const SKIP_TAGS = new Set(['SCRIPT', 'STYLE', 'TEXTAREA', 'INPUT', 'CODE', 'PRE', 'NOSCRIPT']);
    const NOUN_TAGS = new Set(['NNG', 'NNP', 'XR']);

    const localLexiconCache = new Map();
    let enabled = GM_getValue(ENABLED_KEY, true);
    let kiwiPromise = null;

    function loadCache() {
        try {
            const raw = sessionStorage.getItem(CACHE_KEY);
            if (!raw) {
                return;
            }
            const stored = JSON.parse(raw);
            if (!stored || typeof stored !== 'object') {
                return;
            }
            for (const [token, hanja] of Object.entries(stored)) {
                localLexiconCache.set(token, hanja);
            }
        } catch (err) {
            // sessionStorage can be blocked; the in-memory map still works.
        }
    }

    function saveCache() {
        try {
            const stored = {};
            for (const [token, hanja] of localLexiconCache.entries()) {
                stored[token] = hanja;
            }
            sessionStorage.setItem(CACHE_KEY, JSON.stringify(stored));
        } catch (err) {
            // Ignore quota and privacy errors. Memory cache remains.
        }
    }

    function isHangulWord(form) {
        return /^[가-힣]{2,64}$/.test(form);
    }

    function lookupTag(tag) {
        return tag === 'NNP' || tag === 'XR' ? tag : 'NNG';
    }

    function acceptText(node) {
        if (!node || node.nodeType !== Node.TEXT_NODE || !node.nodeValue) {
            return false;
        }
        if (!/[가-힣]{2,}/.test(node.nodeValue)) {
            return false;
        }
        let element = node.parentElement;
        while (element) {
            if (SKIP_TAGS.has(element.tagName) || element.isContentEditable) {
                return false;
            }
            element = element.parentElement;
        }
        return true;
    }

    function getTextNodes(root) {
        if (!root) {
            return [];
        }
        if (root.nodeType === Node.TEXT_NODE) {
            return acceptText(root) ? [root] : [];
        }
        if (root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_FRAGMENT_NODE) {
            return [];
        }

        const nodes = [];
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode(node) {
                return acceptText(node) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
            }
        });
        while (walker.nextNode()) {
            nodes.push(walker.currentNode);
        }
        return nodes;
    }

    function gmRequest(url, responseType, timeout) {
        return new Promise((resolve, reject) => {
            GM_xmlhttpRequest({
                method: 'GET',
                url: url,
                responseType: responseType,
                timeout: timeout,
                onload: (res) => {
                    if (res.status < 200 || res.status >= 300) {
                        reject(new Error('Download failed (' + res.status + '): ' + url));
                        return;
                    }
                    resolve(responseType === 'arraybuffer' ? res.response : res.responseText);
                },
                onerror: (err) => reject(err),
                ontimeout: () => reject(new Error('Download timed out: ' + url))
            });
        });
    }

    function openCacheDb() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(IDB_NAME, 1);
            request.onupgradeneeded = () => {
                const db = request.result;
                if (!db.objectStoreNames.contains('files')) {
                    db.createObjectStore('files');
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    function idbGet(db, key) {
        return new Promise((resolve, reject) => {
            const request = db.transaction('files', 'readonly').objectStore('files').get(key);
            request.onsuccess = () => resolve(request.result || null);
            request.onerror = () => reject(request.error);
        });
    }

    function idbPut(db, key, value) {
        return new Promise((resolve, reject) => {
            const request = db.transaction('files', 'readwrite').objectStore('files').put(value, key);
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    async function cachedFile(db, version, name) {
        const key = version + '/' + name;
        if (db) {
            try {
                const stored = await idbGet(db, key);
                if (stored instanceof ArrayBuffer) {
                    return stored;
                }
            } catch (err) {
                db = null;
            }
        }

        const bytes = await gmRequest(API_ORIGIN + '/kiwi/' + name, 'arraybuffer', 180000);
        if (!(bytes instanceof ArrayBuffer)) {
            throw new Error('Kiwi file was not binary: ' + name);
        }
        if (db) {
            try {
                await idbPut(db, key, bytes);
            } catch (err) {
                // The tab can still use the bytes it just downloaded.
            }
        }
        return bytes;
    }

    async function loadKiwi() {
        if (!kiwiPromise) {
            kiwiPromise = (async () => {
                console.info('[Hanja Converter] Loading Kiwi. The first run downloads about 110MB.');
                const manifest = JSON.parse(await gmRequest(KIWI_MANIFEST, 'text', 30000));
                if (!manifest || !Array.isArray(manifest.files) || !manifest.version) {
                    throw new Error('Kiwi manifest is invalid');
                }

                let db = null;
                try {
                    db = await openCacheDb();
                } catch (err) {
                    db = null;
                }

                const files = {};
                for (const name of manifest.files) {
                    files[name] = await cachedFile(db, manifest.version, name);
                }
                if (db) {
                    db.close();
                }

                const blob = new Blob([files['kiwi-nlp.js']], { type: 'text/javascript' });
                const blobUrl = URL.createObjectURL(blob);
                try {
                    const kiwiModule = await import(blobUrl);
                    const builder = await kiwiModule.KiwiBuilder.create(new Uint8Array(files['kiwi-wasm.wasm']));
                    const modelFiles = {};
                    for (const name of manifest.files) {
                        if (name === 'kiwi-nlp.js' || name === 'kiwi-wasm.wasm') {
                            continue;
                        }
                        modelFiles[name] = new Uint8Array(files[name]);
                    }
                    return await builder.build({
                        modelFiles: modelFiles,
                        modelType: 'cong',
                        integrateAllomorph: false,
                        loadDefaultDict: true,
                        loadTypoDict: false,
                        loadMultiDict: true
                    });
                } finally {
                    URL.revokeObjectURL(blobUrl);
                }
            })().catch((err) => {
                kiwiPromise = null;
                throw err;
            });
        }
        return kiwiPromise;
    }

    function fetchMappingsFromVPS(tokens) {
        return new Promise((resolve, reject) => {
            GM_xmlhttpRequest({
                method: 'POST',
                url: API_URL,
                headers: { 'Content-Type': 'application/json' },
                data: JSON.stringify({ tokens: tokens }),
                timeout: 15000,
                onload: (res) => {
                    if (res.status < 200 || res.status >= 300) {
                        reject(new Error('API Status: ' + res.status));
                        return;
                    }
                    try {
                        const body = JSON.parse(res.responseText);
                        resolve(body.matches || {});
                    } catch (err) {
                        reject(err);
                    }
                },
                onerror: (err) => reject(err),
                ontimeout: () => reject(new Error('API timeout'))
            });
        });
    }

    async function fetchInChunks(queries) {
        const merged = {};
        for (let offset = 0; offset < queries.length; offset += CHUNK_SIZE) {
            const chunk = queries.slice(offset, offset + CHUNK_SIZE);
            const matches = await fetchMappingsFromVPS(chunk);
            Object.assign(merged, matches);
        }
        return merged;
    }

    function remember(queries, form, tag) {
        if (!isHangulWord(form)) {
            return;
        }
        const key = form + ':' + tag;
        if (!localLexiconCache.has(key)) {
            queries.set(key, { form: form, tag: tag });
        }
    }

    function tokenizeAll(kiwi, text) {
        if (text.length <= 4000) {
            return kiwi.tokenize(text, 0, undefined, undefined, 'none');
        }

        const tokens = [];
        let offset = 0;
        while (offset < text.length) {
            let end = Math.min(text.length, offset + 4000);
            if (end < text.length) {
                const space = text.lastIndexOf(' ', end);
                if (space > offset) {
                    end = space + 1;
                }
            }
            const part = text.slice(offset, end);
            const partTokens = kiwi.tokenize(part, 0, undefined, undefined, 'none');
            for (const token of partTokens) {
                tokens.push({
                    tag: token.tag,
                    position: token.position + offset,
                    length: token.length
                });
            }
            offset = end;
        }
        return tokens;
    }

    function planReplacements(text, tokens, queries) {
        const spans = [];
        let lastEnd = 0;
        for (let i = 0; i < tokens.length; i++) {
            const token = tokens[i];
            const end = token.position + token.length;
            if (token.position < lastEnd) {
                continue;
            }
            if (token.position < 0 || end > text.length) {
                return [];
            }

            const surface = text.slice(token.position, end);
            let consumed = end;
            if (NOUN_TAGS.has(token.tag) && isHangulWord(surface)) {
                const next = tokens[i + 1];
                const nextEnd = next ? next.position + next.length : -1;
                if (next && (next.tag === 'XSV' || next.tag === 'XSA') && next.position === end) {
                    remember(queries, surface, next.tag);
                    spans.push({ start: token.position, end: end, key: surface + ':' + next.tag });
                } else if (next && next.tag === 'XSN' && next.position === end) {
                    const compound = text.slice(token.position, nextEnd);
                    const tag = lookupTag(token.tag);
                    if (isHangulWord(compound)) {
                        remember(queries, compound, 'NNG');
                        remember(queries, surface, tag);
                        spans.push({
                            start: token.position,
                            end: nextEnd,
                            key: compound + ':NNG',
                            fallbackKey: surface + ':' + tag,
                            fallbackEnd: end
                        });
                        consumed = nextEnd;
                        i++;
                    } else {
                        remember(queries, surface, tag);
                        spans.push({ start: token.position, end: end, key: surface + ':' + tag });
                    }
                } else {
                    const tag = lookupTag(token.tag);
                    remember(queries, surface, tag);
                    spans.push({ start: token.position, end: end, key: surface + ':' + tag });
                }
            }
            lastEnd = consumed;
        }
        return spans;
    }

    function cachedHanja(key) {
        if (!localLexiconCache.has(key)) {
            return null;
        }
        return localLexiconCache.get(key) || null;
    }

    function applyPlan(text, spans) {
        let cursor = 0;
        let out = '';
        for (const span of spans) {
            if (span.start < cursor || span.end > text.length) {
                return text;
            }
            out += text.slice(cursor, span.start);
            let hanja = cachedHanja(span.key);
            let end = span.end;
            if (!hanja && span.fallbackKey) {
                hanja = cachedHanja(span.fallbackKey);
                end = span.fallbackEnd;
            }
            if (hanja) {
                out += hanja;
                if (end < span.end) {
                    out += text.slice(end, span.end);
                }
            } else {
                out += text.slice(span.start, span.end);
            }
            cursor = span.end;
        }
        out += text.slice(cursor);
        return out;
    }

    async function processRoot(root) {
        if (!enabled || !root || !root.isConnected) {
            return;
        }
        const textNodes = getTextNodes(root);
        if (textNodes.length === 0) {
            return;
        }

        let kiwi;
        try {
            kiwi = await loadKiwi();
        } catch (err) {
            console.error('[Hanja Converter] Kiwi failed to load:', err);
            return;
        }

        const plans = [];
        const queries = new Map();
        for (const node of textNodes) {
            if (!node.isConnected || !node.nodeValue) {
                continue;
            }
            let tokens;
            try {
                tokens = tokenizeAll(kiwi, node.nodeValue);
            } catch (err) {
                console.error('[Hanja Converter] Kiwi tokenize failed:', err);
                continue;
            }
            plans.push({ node: node, text: node.nodeValue, spans: planReplacements(node.nodeValue, tokens, queries) });
        }

        if (queries.size > 0) {
            try {
                const matches = await fetchInChunks(Array.from(queries.values()));
                for (const query of queries.values()) {
                    const key = query.form + ':' + query.tag;
                    localLexiconCache.set(
                        key,
                        Object.prototype.hasOwnProperty.call(matches, key) ? matches[key] : null
                    );
                }
                saveCache();
            } catch (err) {
                console.error('[Hanja Converter] VPS API Error:', err);
            }
        }

        for (const plan of plans) {
            if (!plan.node.isConnected || plan.node.nodeValue !== plan.text) {
                continue;
            }
            const next = applyPlan(plan.text, plan.spans);
            if (next !== plan.text) {
                plan.node.nodeValue = next;
            }
        }
    }

    let chain = Promise.resolve();
    function enqueue(root) {
        chain = chain.then(() => processRoot(root)).catch((err) => {
            console.error('[Hanja Converter]', err);
        });
    }

    loadCache();
    GM_registerMenuCommand('Toggle Hanja conversion', () => {
        enabled = !enabled;
        GM_setValue(ENABLED_KEY, enabled);
        console.info('[Hanja Converter] ' + (enabled ? 'Enabled' : 'Disabled'));
        if (enabled && document.body) {
            enqueue(document.body);
        }
    });

    if (enabled && document.body) {
        enqueue(document.body);
    }

    let pendingRoots = new Set();
    let timer = null;
    const observer = new MutationObserver((mutations) => {
        if (!enabled) {
            return;
        }
        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
                if (node.nodeType === Node.ELEMENT_NODE || node.nodeType === Node.TEXT_NODE) {
                    pendingRoots.add(node);
                }
            }
        }
        if (pendingRoots.size === 0) {
            return;
        }
        clearTimeout(timer);
        timer = setTimeout(() => {
            const roots = Array.from(pendingRoots).filter((node) => node.isConnected);
            pendingRoots = new Set();
            const rootSet = new Set(roots);
            for (const node of roots) {
                let parent = node.parentNode;
                let covered = false;
                while (parent) {
                    if (rootSet.has(parent)) {
                        covered = true;
                        break;
                    }
                    parent = parent.parentNode;
                }
                if (!covered) {
                    enqueue(node);
                }
            }
        }, 250);
    });

    observer.observe(document.documentElement, { childList: true, subtree: true });
})();
