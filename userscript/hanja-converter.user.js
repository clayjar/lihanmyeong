// ==UserScript==
// @name         VPS-Backed Hangeul to Hanja Converter
// @namespace    hanja-lexicon
// @version      1.0.0
// @description  Replace Hangeul words with Hanja via a remote lexicon API
// @match        *://*/*
// @run-at       document-idle
// @grant        GM_xmlhttpRequest
// @connect      *
// ==/UserScript==

(function () {
    'use strict';

    // Origin that serves this project's public/index.php. No trailing slash.
    const API_ORIGIN = 'https://example.com';
    const API_URL = API_ORIGIN + '/api/v1/hanja/batch';
    const CACHE_KEY = 'hanjaLexiconCache.v1';
    const CHUNK_SIZE = 1000;
    const SKIP_TAGS = new Set(['SCRIPT', 'STYLE', 'TEXTAREA', 'INPUT', 'CODE', 'PRE', 'NOSCRIPT']);

    const localLexiconCache = new Map();

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

    function fetchMappingsFromVPS(tokens) {
        return new Promise((resolve, reject) => {
            GM_xmlhttpRequest({
                method: 'POST',
                url: API_URL,
                headers: { 'Content-Type': 'application/json' },
                data: JSON.stringify({ tokens }),
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

    async function fetchInChunks(tokens) {
        const merged = {};
        for (let offset = 0; offset < tokens.length; offset += CHUNK_SIZE) {
            const chunk = tokens.slice(offset, offset + CHUNK_SIZE);
            const matches = await fetchMappingsFromVPS(chunk);
            Object.assign(merged, matches);
        }
        return merged;
    }

    function replaceTextNodes(textNodes) {
        for (const node of textNodes) {
            if (!node.nodeValue) {
                continue;
            }
            const next = node.nodeValue.replace(/[가-힣]{2,}/g, (matched) => {
                if (!localLexiconCache.has(matched)) {
                    return matched;
                }
                return localLexiconCache.get(matched) || matched;
            });
            if (next !== node.nodeValue) {
                node.nodeValue = next;
            }
        }
    }

    async function processRoot(root) {
        if (!root || !root.isConnected) {
            return;
        }
        const textNodes = getTextNodes(root);
        if (textNodes.length === 0) {
            return;
        }

        const uniqueTokens = new Set();
        for (const node of textNodes) {
            const words = node.nodeValue.match(/[가-힣]{2,}/g);
            if (!words) {
                continue;
            }
            for (const word of words) {
                if (!localLexiconCache.has(word)) {
                    uniqueTokens.add(word);
                }
            }
        }

        if (uniqueTokens.size > 0) {
            try {
                const newMappings = await fetchInChunks(Array.from(uniqueTokens));
                for (const token of uniqueTokens) {
                    localLexiconCache.set(token, Object.prototype.hasOwnProperty.call(newMappings, token)
                        ? newMappings[token]
                        : null);
                }
                saveCache();
            } catch (err) {
                console.error('[Hanja Converter] VPS API Error:', err);
            }
        }

        replaceTextNodes(textNodes);
    }

    let chain = Promise.resolve();
    function enqueue(root) {
        chain = chain.then(() => processRoot(root)).catch((err) => {
            console.error('[Hanja Converter]', err);
        });
    }

    loadCache();
    if (document.body) {
        enqueue(document.body);
    }

    let pendingRoots = new Set();
    let timer = null;
    const observer = new MutationObserver((mutations) => {
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
