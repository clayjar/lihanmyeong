# Changelog

## 1.0.0

- Load the Hangeul-to-Hanja lexicon into `hanja_lexicon` from `data/hanja.txt`, keeping the first spelling when a word is listed twice.
- Serve matches from `POST /api/v1/hanja/batch`, appending a trailing particle when only the stem is in the lexicon.
- Add a Tampermonkey script that requests the words on the page and replaces each full Hangul word.
