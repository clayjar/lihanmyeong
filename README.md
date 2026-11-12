# Lihanmyeong

Hangeul-to-Hanja conversion backed by MySQL. A Tampermonkey script runs [Kiwi](https://github.com/bab2min/Kiwi) in the browser, keeps nouns, and asks the server only for those stems. The lexicon stays on the server, so a dictionary fix does not require shipping a new userscript.

MySQL 기반의 한글-한자 변환 시스템입니다. Tampermonkey 스크립트가 브라우저 내에서 [Kiwi](https://github.com/bab2min/Kiwi)를 실행하여 명사를 추출한 뒤, 해당 어간에 대해서만 서버에 변환을 요청합니다. 어휘 데이터가 서버에 저장되어 있으므로, 사전 내용을 수정하더라도 사용자 스크립트(userscript)를 새로 배포할 필요가 없습니다.

## Requirements

- PHP 8.2 or newer, with `pdo_mysql` and `mbstring`
- MySQL or MariaDB
- Tampermonkey (or another userscript manager that supports `GM_xmlhttpRequest`)
- nginx (or similar) with the web root set to `public/`
- Node.js, including `npx`, to download Kiwi
- About 110MB of disk space for the Kiwi model

## Setup

Create the database and user, then copy `.env.example` to `.env` in the project root and set the database name, user, and password. `.env` is not web-accessible once `public/` is the document root.

Create the lexicon table:

```sql
CREATE TABLE hanja_lexicon (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hangeul VARCHAR(64) NOT NULL,
    hanja VARCHAR(64) NOT NULL,
    pos VARCHAR(16) DEFAULT NULL,
    priority TINYINT DEFAULT 0,
    UNIQUE KEY idx_hangeul_pos (hangeul, pos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
```

`data/hanja.txt` is a JSON object of Hangeul keys and Hanja values, from Discord user @lihanmyeong. Load it with:

```bash
php bin/import-lexicon.php
```

The importer trims entries, drops keys that are not pure Hangul, and stores spaced compounds without spaces (`국제 연합` becomes `국제연합`). When a row is only a word plus a particle (`부서를` / `部署를`), it stores the stem. A `하다` row such as `사과하` / `謝過하` is stored as the stem `사과` with part of speech `XSV` and `XSA`, and does not replace a plain noun that is already there (`사과` / `沙果`). The first spelling wins when the same word and part of speech appear twice. `pos` is `NNG` for plain nouns. Running the importer again inserts nothing new.

Download the Kiwi 0.23.0 engine and model into `public/kiwi/`:

```bash
php bin/fetch-kiwi.php
```

Kiwi is LGPL-2.1-or-later. Those downloaded files are separate from this project's MIT license.

It prints `inserted`, `already present`, `skipped`, and `collisions`.

## API

`POST /api/v1/hanja/batch`

```bash
curl -sS -X POST https://lihanmyeong.example.com/api/v1/hanja/batch \
  -H 'Content-Type: application/json' \
  -d '{"tokens":["비행기","학교에서","경제적","가운데"]}'
```

```json
{
  "matches": {
    "비행기": "飛行機",
    "학교에서": "學校에서",
    "경제적": "經濟的"
  }
}
```

Send up to 1,000 unique tokens. A string is two or more Hangul syllables. An exact `NNG` row wins. Otherwise, if the word ends in a particle and the stem is in the lexicon, the response is the stem's Hanja plus that particle. Words with no match, such as `가운데`, are omitted.

The userscript sends the stem and the Kiwi tag instead:

```bash
curl -sS -X POST https://lihanmyeong.example.com/api/v1/hanja/batch \
  -H 'Content-Type: application/json' \
  -d '{"tokens":[{"form":"사과","tag":"NNG"},{"form":"사과","tag":"XSV"}]}'
```

```json
{
  "matches": {
    "사과:NNG": "沙果",
    "사과:XSV": "謝過"
  }
}
```

`NNG`, `NNP`, and `XR` read the plain noun. `XSV` and `XSA` read only that sense, so a verbal noun is left unchanged when the lexicon has no separate reading. The response value is the Hanja stem. The script puts particles and endings back from the original text.

Particles, longest match first: 에서, 으로, 에게, 한테, 까지, 부터, 처럼, 보다, 하고, 이, 가, 은, 는, 을, 를, 의, 에, 로, 과, 와, 도, 만.

A missing `tokens` array returns 400. Any method other than POST returns 405.

## Userscript

Install [`userscript/hanja-converter.user.js`](userscript/hanja-converter.user.js) in Tampermonkey. Before saving it, set `API_ORIGIN` to the origin that serves this app, with no trailing slash (`https://lihanmyeong.example.com`). The script calls that host with `GM_xmlhttpRequest`, so the page's Content-Security-Policy does not block the lookup. `@connect *` allows that request. To limit the script to one host, replace `*` with that hostname.

Conversion is on by default. The Tampermonkey menu command "Toggle Hanja conversion" turns it off and remembers that choice. While it is on, a page is analyzed only if it contains Hangul. The first visit to a site downloads about 110MB into that site's IndexedDB; later visits on the same site reuse the cache. Each tab still builds the model in memory, which takes a few seconds and a few hundred megabytes. Building does not start on pages without Hangul.

Kiwi tags the text. The script replaces `NNG`, `NNP`, and `XR` spans of at least two syllables, using the token offsets, so `가운데` stays one word. A noun followed by `XSV` or `XSA` uses that sense (`사과했다` can become `謝過했다` while a plain `사과` stays `沙果`). A noun followed by `XSN` prefers the whole compound when the lexicon has it (`경제적` becomes `經濟的`). Adverbs such as `가장` in `가장 빠른` are not sent to the server. The script skips `script`, `style`, `textarea`, `input`, `code`, `pre`, `noscript`, and contenteditable nodes, and it watches for text added after the first load.

## Layout

```
bin/import-lexicon.php   Load data/hanja.txt into MySQL
bin/fetch-kiwi.php       Download the Kiwi engine and model
data/hanja.txt           Source dictionary
public/index.php         Batch API
public/kiwi/             Kiwi files created by fetch-kiwi.php
src/                     Database, particle, and lookup code
userscript/              Tampermonkey script
```

## Credits

The dictionary in [`data/hanja.txt`](data/hanja.txt) and the original Tampermonkey userscript was created by Discord user @lihanmyeong.

Morphological analysis uses [Kiwi](https://github.com/bab2min/Kiwi) by bab2min, licensed under LGPL-2.1-or-later.

## License

[MIT](LICENSE)
