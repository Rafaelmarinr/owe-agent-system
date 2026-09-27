# Project File Bridge Request Schema

This bridge modifies PHP and CSS files belonging to an approved child theme or
a custom plugin root declared in `.owe/project-file-policy.json`.

Requests must be stored in `.owe/requests/current-php.json` and replaced fully
before each authorized operation.

```json
{
  "schema": "owe-project-file-bridge/1.1",
  "operation": "upsert",
  "user_confirmed": true,
  "authorization": {
    "user_confirmed": true,
    "barrier_exceptions": ["project.php_file", "project.css_file", "project.create_file"],
    "direct_scope": {
      "operations": ["replace", "create"],
      "files": [
        "wp-content/plugins/my-plugin/includes/cart.php",
        "wp-content/themes/my-child/assets/css/cart.css"
      ]
    }
  },
  "files": [
    {
      "operation": "replace",
      "path": "wp-content/plugins/my-plugin/includes/cart.php",
      "expected_hash": "SHA256_FROM_INSPECTION",
      "change_summary": "Apply the approved WooCommerce behavior",
      "content": "<?php\n...\n"
    },
    {
      "operation": "create",
      "path": "wp-content/themes/my-child/assets/css/cart.css",
      "expected_hash": null,
      "change_summary": "Add approved cart styles",
      "content": "selector .cart { display: block; }\n"
    }
  ]
}
```

Commands:

```bash
bash .opencode/tools/project-file-bridge/bridge.sh inspect --path "wp-content/themes/my-child/functions.php"
bash .opencode/tools/project-file-bridge/bridge.sh inspect --request .owe/requests/current-php.json
bash .opencode/tools/project-file-bridge/bridge.sh diff --request .owe/requests/current-php.json
bash .opencode/tools/project-file-bridge/bridge.sh validate --request .owe/requests/current-php.json
bash .opencode/tools/project-file-bridge/bridge.sh apply --request .owe/requests/current-php.json
```

The direct `inspect --path` form is read-only and is intended for the first
step of a change. The request form may also contain entries with only `path`
for the same initial inspection. `diff`, `validate` and `apply` require the
current hash and complete replacement content.

The bridge validates the current hash for existing files, CSS/PHP content,
allowed ownership, explicit task authorization, atomic write and persisted
output. Creating a file always requires `project.create_file`, an exact path
in `direct_scope.files` and explicit confirmation. It returns `APPLIED`,
`VALIDATED`, `INSPECTED` or `BLOCKED:<reason>`.
