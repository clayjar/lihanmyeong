# Lihanmyeong

Hangeul-to-Hanja conversion backed by MySQL. A Tampermonkey script collects the Hangul words on a page, asks the server for matches, and replaces each whole word. The lexicon stays on the server, so a dictionary fix does not require shipping a new userscript.

## Requirements

- PHP 8.2 or newer, with `pdo_mysql` and `mbstring`
- MySQL or MariaDB
- Tampermonkey (or another userscript manager that supports `GM_xmlhttpRequest`)
- nginx (or similar) with the web root set to `public/`

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
    UNIQUE KEY idx_hangeul (hangeul)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
```

`data/hanja.txt` is a JSON object of Hangeul keys and Hanja values, from Discord user @lihanmyeong. Load it with:

```bash
php bin/import-lexicon.php
```

The importer trims entries, drops keys that are not pure Hangul, and stores spaced compounds without spaces (`국제 연합` becomes `국제연합`). When a row is only a word plus a particle (`부서를` / `部署를`), it stores the stem. The first spelling wins when the same word appears twice. Running the importer again inserts nothing new.

It prints `inserted`, `already present`, `skipped`, and `collisions`.

## API

`POST /api/v1/hanja/batch`

```bash
curl -sS -X POST https://example.com/api/v1/hanja/batch \
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

Send up to 1,000 unique tokens. Anything that is not two or more Hangul syllables is ignored. An exact lexicon row wins. Otherwise, if the word ends in a particle and the stem is in the lexicon, the response is the stem's Hanja plus that particle. Words with no match, such as `가운데`, are omitted.

Particles, longest match first: 에서, 으로, 에게, 한테, 까지, 부터, 처럼, 보다, 하고, 이, 가, 은, 는, 을, 를, 의, 에, 로, 과, 와, 도, 만.

A missing `tokens` array returns 400. Any method other than POST returns 405.

## Userscript

Install [`userscript/hanja-converter.user.js`](userscript/hanja-converter.user.js) in Tampermonkey. Before saving it, set `API_ORIGIN` to the origin that serves this app, with no trailing slash (`https://example.com`). The script calls that host with `GM_xmlhttpRequest`, so the page's Content-Security-Policy does not block the lookup. `@connect *` allows that request. To limit the script to one host, replace `*` with that hostname.

The script skips `script`, `style`, `textarea`, `input`, `code`, `pre`, `noscript`, and contenteditable nodes. It replaces only a full Hangul run, caches misses for the browser session, and watches for text added after the first load.

## Layout

```
bin/import-lexicon.php   Load data/hanja.txt into MySQL
data/hanja.txt           Source dictionary
public/index.php         Batch API
src/                     Database, particle, and lookup code
userscript/              Tampermonkey script
```

## Credits

The dictionary in [`data/hanja.txt`](data/hanja.txt) was created by Discord user @lihanmyeong.

## License

[MIT](LICENSE)
