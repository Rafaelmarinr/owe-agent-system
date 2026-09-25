# WordPress Content Bridge request schema

Read this file only when an authorized native WordPress content mutation is about to be applied.

The bridge uses WordPress APIs, never direct SQL. It rejects Elementor-built posts, deletion, arbitrary metadata, plugin settings and unrecognized fields. Reuse `.owe/requests/current-content.json` instead of accumulating request files.

## Create a native post, page or public custom post

```json
{
  "schema": "owe-wordpress-content-bridge/1.0",
  "operation": "create_post",
  "post_type": "post",
  "fields": {
    "title": "Approved title",
    "content": "Approved native WordPress content",
    "excerpt": "",
    "status": "draft",
    "slug": "approved-title"
  },
  "taxonomies": {
    "category": [3],
    "post_tag": [8, 12]
  }
}
```

Only existing term IDs can be assigned. Create a missing category or term with `create_term` first. Allowed statuses are `draft`, `pending`, `private` and `publish`; publishing must be explicitly included in the authorized plan.

## Update native content

```json
{
  "schema": "owe-wordpress-content-bridge/1.0",
  "operation": "update_post",
  "target": "POST_ID_OR_LOCAL_URL",
  "expected_content_hash": "HASH_RETURNED_BY_INSPECT",
  "fields": {
    "title": "Approved replacement title",
    "content": "Approved replacement content"
  }
}
```

Only the included fields change. Allowed fields: `title`, `content`, `excerpt`, `status` and `slug`.

## Create or update a category or term

```json
{
  "schema": "owe-wordpress-content-bridge/1.0",
  "operation": "create_term",
  "taxonomy": "category",
  "fields": {
    "name": "Approved category",
    "slug": "approved-category",
    "description": "",
    "parent": 0
  }
}
```

For `update_term`, also include `term_id` and `expected_term_hash` from `inspect-term`.

## Find an existing category or term

Use an exact name or an exact `slug` within one taxonomy. Supply exactly one selector:

```bash
bash .opencode/tools/wordpress-content-bridge/bridge.sh find-term --taxonomy "category" --name "Color & Balayage"
bash .opencode/tools/wordpress-content-bridge/bridge.sh find-term --taxonomy "category" --slug "color-balayage"
```

The lookup is read-only and includes empty terms. It writes `.owe/runtime/wordpress-term-search.json` and prints the matching term IDs. Zero matches is a valid result. Because hierarchical taxonomies can contain repeated names, a name lookup can return several matches; never choose one arbitrarily. Use an exact `slug`, parent information or an explicit user decision to disambiguate.

## Assign existing terms

```json
{
  "schema": "owe-wordpress-content-bridge/1.0",
  "operation": "assign_terms",
  "target": "POST_ID_OR_LOCAL_URL",
  "expected_content_hash": "HASH_RETURNED_BY_INSPECT",
  "taxonomy": "category",
  "term_ids": [3, 7],
  "mode": "replace"
}
```

`replace` sets the exact list; `append` preserves current terms and adds the supplied IDs.

## Apply a native-content batch

Use `apply_batch` when one authorized task contains several creations or taxonomy assignments. It executes up to 250 ordered actions in one PHP process. Every action needs a unique lowercase `key`; later actions can reference earlier creations with `@key`.

```json
{
  "schema": "owe-wordpress-content-bridge/1.0",
  "operation": "apply_batch",
  "actions": [
    {
      "key": "category-facials",
      "type": "create_term",
      "taxonomy": "portfolio_category",
      "fields": {
        "name": "Facials",
        "slug": "facials",
        "description": "",
        "parent": 0
      }
    },
    {
      "key": "portfolio-anti-aging",
      "type": "create_post",
      "post_type": "portfolio",
      "fields": {
        "title": "Anti-Aging Facial",
        "content": "",
        "excerpt": "",
        "status": "publish",
        "slug": "anti-aging-facial"
      },
      "taxonomies": {
        "portfolio_category": ["@category-facials"]
      }
    },
    {
      "key": "categorize-existing-post",
      "type": "assign_terms",
      "target": "123",
      "expected_content_hash": "HASH_RETURNED_BY_INSPECT",
      "taxonomy": "portfolio_category",
      "terms": ["@category-facials", 8],
      "mode": "replace"
    }
  ]
}
```

Batch rules:

- Supported action types are only `create_term`, `create_post` and `assign_terms`.
- Put a referenced `create_term` or `create_post` before the action that uses it.
- Batch-created terms and posts require an explicit unique `slug`; existing slugs or duplicate actions block the complete batch before writes begin.
- Taxonomy lists accept existing positive term IDs and `@key` references to earlier `create_term` actions in the same taxonomy.
- `assign_terms.target` accepts an existing post ID/local URL or `@key` for an earlier `create_post`. Existing targets require a fresh `expected_content_hash`; batch-created targets do not.
- A failed action restores earlier taxonomy assignments and permanently removes only posts or terms created by that failed batch. It never deletes content that existed before the batch.
- A successful batch writes its detailed deterministic report to `.owe/runtime/wordpress-content-batch.json`; use the concise terminal summary unless action-level IDs are required. Its `assignments` counter includes taxonomy maps applied while creating posts and explicit `assign_terms` actions.
- `apply_batch` does not update existing text, edit Elementor data, process media, users, plugin settings, WooCommerce product data or arbitrary metadata.

## Commands

```bash
bash .opencode/tools/wordpress-content-bridge/bridge.sh check
bash .opencode/tools/wordpress-content-bridge/bridge.sh inspect --post "LOCAL_URL_OR_ID"
bash .opencode/tools/wordpress-content-bridge/bridge.sh inspect --post "LOCAL_URL_OR_ID" --include-content yes
bash .opencode/tools/wordpress-content-bridge/bridge.sh inspect --post "LOCAL_URL_OR_ID" --include-terms yes
bash .opencode/tools/wordpress-content-bridge/bridge.sh inspect-term --taxonomy "category" --term-id "3"
bash .opencode/tools/wordpress-content-bridge/bridge.sh find-term --taxonomy "category" --name "Color & Balayage"
bash .opencode/tools/wordpress-content-bridge/bridge.sh find-term --taxonomy "category" --slug "color-balayage"
bash .opencode/tools/wordpress-content-bridge/bridge.sh apply --request ".owe/requests/current-content.json"
```

`inspect` writes `.owe/runtime/wordpress-content.json`. By default it omits the full body and term list to avoid unnecessary model context. Use `--include-content yes` only when the current text must be reviewed or rewritten, and `--include-terms yes` only for taxonomy work. It reports `content_mode: elementor` without exposing Elementor data when routing to Elementor Bridge is required.
