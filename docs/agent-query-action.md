# `query` sync action — agent guide

This component exposes a synchronous **`query`** action for use by an LLM agent that is configuring the Google Drive Extractor on behalf of a user. The action lets the agent inspect a Google Spreadsheet — listing tabs, dimensions, and reading arbitrary cell ranges — and receive the result as JSON in the action response.

It is intended for **fast, exploratory introspection**: confirm a `fileId` is reachable, enumerate tabs, sample a few rows, and validate that the right `columnRange` and `header.rows` settings will produce sensible output — before committing to a long-running extraction (`action: "run"`).

## Prerequisites

The `query` action reuses the same authentication as `run`. Set up auth first; see the **OAuth Registration** section in the project [README](../README.md) for OAuth, or supply `parameters.#serviceAccount` (encrypted JSON of a Google service account that has been granted at least viewer access to the target spreadsheet).

A typical workflow:

1. Set up authentication (OAuth or service account).
2. Call `query` **without** `parameters.query` to get the spreadsheet's metadata (tabs, sizes).
3. Call `query` **with** `parameters.query` set to a small A1 range to sample headers and a few rows.
4. Once the agent knows what the user wants, write the real `run` configuration with `sheets: [...]`.

## Invocation

Set `action` to `"query"` and provide `parameters.fileId` plus an optional `parameters.query` (an [A1 notation](https://developers.google.com/sheets/api/guides/concepts#cell) range, optionally prefixed with the tab name):

```json
{
  "action": "query",
  "authorization": {
    "oauth_api": { "credentials": { "appKey": "...", "#appSecret": "...", "#data": "..." } }
  },
  "parameters": {
    "fileId": "1AbC_spreadsheet_id",
    "query": "Sheet1!A1:E50"
  }
}
```

Service-account variant:

```json
{
  "action": "query",
  "parameters": {
    "#serviceAccount": "<encrypted JSON>",
    "fileId": "1AbC_spreadsheet_id",
    "query": "Sheet1!A1:E50"
  }
}
```

The component returns its result as a single JSON document on stdout.

## Response shape

### Metadata mode (no `parameters.query`)

```json
{
  "status": "success",
  "spreadsheet": {
    "spreadsheetId": "1AbC_spreadsheet_id",
    "title": "Quarterly numbers",
    "sheets": [
      { "sheetId": 0, "title": "Sheet1", "rowCount": 1000, "columnCount": 26 },
      { "sheetId": 1234567890, "title": "Raw data", "rowCount": 50000, "columnCount": 12 }
    ]
  }
}
```

`sheetId` is the Google-internal numeric `gid` (the same value you pass to `parameters.sheets[*].sheetId` in a `run` config). `title` is the human-visible tab name (the same value as `sheetTitle`).

### Data mode (with `parameters.query`)

```json
{
  "status": "success",
  "range": "Sheet1!A1:E50",
  "values": [
    ["id", "name", "country", "amount", "currency"],
    ["1", "Alice", "CZ", "120", "CZK"],
    ["2", "Bob", "US", "45", "USD"]
  ]
}
```

- `values` is the raw `values` array as returned by the Sheets API: an array of rows, each row an array of cell strings. Trailing empty cells in a row are omitted by the API; rows entirely past the last non-empty row are not returned at all.
- `range` echoes the API's resolved range (often expanded to include the tab name, e.g. `Sheet1!A1:E50`).
- An empty sheet/range returns `"values": []`.

### Errors

On bad input (missing `fileId`, invalid A1 syntax, unreachable spreadsheet, expired credentials, etc.) the action exits non-zero with a `UserException` whose message is the underlying API error. Invalid OAuth/service-account credentials surface as an authorization error.

## A1 notation cheatsheet

- `Sheet1!A1:E50` — rows 1–50, columns A–E of `Sheet1`.
- `Sheet1!A:E` — every row, columns A–E.
- `Sheet1` — the whole tab.
- `A1:E10` — assumes the first tab; prefer the explicit form.
- Tab names containing spaces or special characters must be single-quoted: `'My Sheet'!A1:E10`.

## Limits and guidance

There is **no server-side row cap and no read-only enforcement** on this action. Both are deliberately the agent's responsibility:

- **Keep ranges small.** Sync-action responses travel through the Keboola platform's stdout channel and very large payloads will be truncated or rejected. A few hundred cells is generally safe; tens of thousands is not.
- **Always start with metadata** (no `query`) before reading values — it's cheap and tells you the actual `rowCount`/`columnCount` so you can pick a sensible range.
- **Sample before sweeping.** `Sheet1!A1:Z10` to see the header layout is almost always enough to write the right `run` config.
- The action is **read-only by API surface** — `getSpreadsheetValues` is a GET — but treat the spreadsheet as production data anyway.

## Mapping back to a `run` config

After the metadata + sample loop, the agent has everything needed to populate a `run` config:

| `run` field                       | Source from `query` response                                       |
| --------------------------------- | ------------------------------------------------------------------ |
| `parameters.sheets[*].fileId`     | Same `fileId` you passed in.                                       |
| `parameters.sheets[*].fileTitle`  | `spreadsheet.title` from metadata mode.                            |
| `parameters.sheets[*].sheetId`    | `spreadsheet.sheets[*].sheetId` (the numeric gid).                 |
| `parameters.sheets[*].sheetTitle` | `spreadsheet.sheets[*].title`.                                     |
| `parameters.sheets[*].columnRange`| Decided from sampling: e.g. `"A:E"` if data lives in cols A–E.     |
| `parameters.sheets[*].header.rows`| `0` if the sample's first row already looks like data; `1` if it's a header.|
| `parameters.sheets[*].outputTable`| The agent / user picks; not derivable from the spreadsheet.        |

## Recommended discovery sequence

1. **Metadata** — list tabs and sizes:
   ```json
   { "action": "query", "parameters": { "fileId": "1AbC...", "#serviceAccount": "..." } }
   ```
2. **Header sample** — first 10 rows of the candidate tab:
   ```json
   { "action": "query", "parameters": { "fileId": "1AbC...", "query": "Sheet1!A1:Z10", "#serviceAccount": "..." } }
   ```
3. **Bottom-of-data probe** (optional) — last 5 rows, to confirm the data ends where metadata says:
   ```json
   { "action": "query", "parameters": { "fileId": "1AbC...", "query": "Sheet1!A995:Z1000", "#serviceAccount": "..." } }
   ```

After this, write the real `run` configuration.

## Related actions

- `run` — the actual extraction. Asynchronous, writes output tables to the data directory. Not for use during agent configuration.
