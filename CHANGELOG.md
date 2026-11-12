# Changelog

## 1.1.0

- Run Kiwi in the browser and replace only the nouns it tags, using character offsets so a word like `가운데` is never split.
- Keep a separate Hanja reading for `하다` stems, so `사과했다` can use `謝過` without changing the noun `사과`.
- Cache the Kiwi engine and model in IndexedDB. Load them only on pages that contain Hangul, and turn conversion off from the Tampermonkey menu.

## 1.0.0

- Load the Hangeul-to-Hanja lexicon into `hanja_lexicon` from `data/hanja.txt`, keeping the first spelling when a word is listed twice.
- Serve matches from `POST /api/v1/hanja/batch`, appending a trailing particle when only the stem is in the lexicon.
- Add a Tampermonkey script that requests the words on the page and replaces each full Hangul word.
