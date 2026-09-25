# Elementor Bridge request schema

Read this file only when an authorized Elementor mutation is about to be applied.

Requests live in `.owe/requests/`. Reuse `.owe/requests/current.json` instead of accumulating one file per attempt. Use this base:

```json
{
  "schema": "owe-elementor-bridge/1.0",
  "page": "PAGE_ID_OR_LOCAL_URL",
  "device": "desktop",
  "operation": "append_section",
  "expected_page_hash": "HASH_RETURNED_BY_CHECK_OR_INSPECT",
  "requires_pro": false,
  "target_section_id": "",
  "section": {}
}
```

Desktop operations:

- `append_section`: adds exactly one new root section; no target id.
- `insert_section_before`: requires a root `target_section_id`.
- `insert_section_after`: requires a root `target_section_id`.
- `replace_section`: requires a root target and the replacement must preserve its id.

The `section` must be one valid Elementor root container/section. Every element needs a unique 6–12 character lowercase hexadecimal id, an `elType`, `settings` and `elements`. Widgets also need `widgetType`.

## Enable Edit with Elementor

`check` is read-only. When a destination supported by Elementor does not use the editor yet, it returns `NEEDS_ELEMENTOR_ACTIVATION`, whether native content is present, whether automatic activation is supported and a concurrency `source_hash`.

After the user explicitly accepts activation, and only when `activation_supported=yes`, use:

```json
{
  "schema": "owe-elementor-bridge/1.0",
  "page": "TARGET_POST_ID_OR_LOCAL_URL_OR_SLUG",
  "device": "desktop",
  "operation": "enable_elementor_editor",
  "expected_source_hash": "HASH_FROM_CHECK",
  "native_content_policy": "require_empty",
  "user_confirmed": true
}
```

`user_confirmed` records the explicit decision communicated by Donna and must be `true`. This operation only calls Elementor's official `set_is_built_with_elementor(true)` API and verifies the resulting document. It does not convert native content, read or save Elementor elements, or insert a template. Automatic activation is limited to empty destinations that Elementor officially accepts through `Elementor\Utils::is_post_support()`, including enabled custom post types, and without any partial `_elementor_*` metadata. Run `check` again after activation and use its new page hash for the requested mutation.

## Inspect and update standard page attributes

Inspect the current values and the templates registered for the destination:

```bash
bash .opencode/tools/elementor-bridge/bridge.sh inspect-attributes --page "LOCAL_URL_OR_ID"
```

The report contains `page_hash`, `attributes_hash`, the current template metadata, parent, menu order, available template slugs and whether the post type supports parent/order. After explicit authorization, include only the attributes that must change:

```json
{
  "schema": "owe-elementor-bridge/1.0",
  "page": "TARGET_POST_ID_OR_LOCAL_URL_OR_SLUG",
  "device": "desktop",
  "operation": "update_page_attributes",
  "expected_page_hash": "HASH_FROM_INSPECTION",
  "expected_attributes_hash": "ATTRIBUTES_HASH_FROM_INSPECTION",
  "expected_templates_hash": "TEMPLATES_HASH_FROM_INSPECTION",
  "user_confirmed": true,
  "attributes": {
    "template": "elementor_header_footer",
    "parent_id": 0,
    "menu_order": 4
  }
}
```

Allowed attributes are `template`, `parent_id` and `menu_order`. `template` accepts `default` or any exact slug returned in `available_templates`; Elementor Full Width is `elementor_header_footer`. Parent and order require a hierarchical post type. A parent must exist, use the same post type and not create a cycle. The operation uses `wp_update_post()` for parent/order and the WordPress metadata API exclusively for the allowlisted `_wp_page_template` key. It never invokes Elementor document save, writes arbitrary metadata or changes Elementor elements, and verifies that the complete Elementor tree remains unchanged.

## Find and inspect a local template

Use exactly one selector. Slug is the default user-facing identifier and never falls back to a name search:

```bash
bash .opencode/tools/elementor-bridge/bridge.sh find-template --slug "for-services"
bash .opencode/tools/elementor-bridge/bridge.sh find-template --name "For Services"
```

Searches are exact, local and limited to editable `elementor_library` records of type `section`, `container` or `page`. The report contains ID, title, slug, type and status. Never choose arbitrarily when a name returns two or more matches.

Inspect the selected result before planning a mutation:

```bash
bash .opencode/tools/elementor-bridge/bridge.sh inspect-template --template-id "123"
```

The inspection returns the current template hash, root count, structure summary, unavailable widgets, Elementor Pro requirement and available page-setting keys.

## Insert a local template

After the user approves the selected template, position and page-settings decision:

```json
{
  "schema": "owe-elementor-bridge/1.0",
  "page": "TARGET_POST_ID_OR_LOCAL_URL_OR_SLUG",
  "device": "desktop",
  "operation": "insert_template",
  "expected_page_hash": "HASH_FROM_TARGET_CHECK",
  "requires_pro": false,
  "template_id": 123,
  "expected_template_hash": "HASH_FROM_TEMPLATE_INSPECTION",
  "position": "append",
  "target_section_id": "",
  "apply_page_settings": false
}
```

Positions:

- `prepend`: insert all template roots at the beginning.
- `append`: insert all template roots at the end.
- `before`: insert before `target_section_id`.
- `after`: insert after `target_section_id`.
- `replace`: replace all current Elementor content with the template.

`apply_page_settings` is mandatory. It can be `true` only for a `page` template and only after the user explicitly chooses to apply its document settings. The Bridge loads the template through Elementor's local Template Library API, requires regenerated element IDs, rewrites recognized internal element references, rejects collisions or unavailable widgets, preflights Elementor normalization, saves once and verifies the persisted document. Headers, footers, popups, Theme Builder, Loop Items and unknown template types are rejected.

## Update widget copy

First inspect only the authorized section:

```bash
bash .opencode/tools/elementor-bridge/bridge.sh inspect-content --page "LOCAL_URL_OR_ID" --section-id "ROOT_SECTION_ID"
```

The compact report contains only fields included in OWE's explicit editorial catalog for installed official Elementor and Elementor Pro widgets. Unknown or functional controls remain read-only until reviewed; control type alone never grants write access. The report omits layout and style settings. Dynamic Tags, addons, shortcodes, URLs, custom HTML and non-text controls are not editable. Repeater fields use their stable `_id` in the generated `ref`.

After the copy is approved, group all changes for that section in one request:

```json
{
  "schema": "owe-elementor-bridge/1.0",
  "page": "PAGE_ID_OR_LOCAL_URL",
  "device": "all",
  "operation": "update_widget_content",
  "expected_page_hash": "HASH_FROM_INSPECT_CONTENT",
  "requires_pro": false,
  "target_section_id": "ROOT_SECTION_ID",
  "updates": [
    {
      "ref": "ELEMENT_ID:CONTROL_NAME",
      "expected_content_hash": "HASH_FROM_INSPECT_CONTENT",
      "value": "Approved replacement copy"
    }
  ]
}
```

Use each `ref` and hash exactly as inspected. `device` is `all` because Elementor copy is shared by all viewports. A request accepts at most 100 fields. Plain controls allow at most 10 KiB; WYSIWYG controls allow at most 100 KiB and safe WordPress post HTML. Sanitization is fail-closed: if WordPress would remove markup, the operation is blocked instead of silently changing the approved copy.

The bridge verifies the page hash again immediately before writing, plus each content hash, target section, official widget controls, Elementor's pre-save normalized representation, exact persisted result and section isolation. Links, embedded resources, functional attributes and shortcodes inside HTML must remain byte-for-byte identical. It also restores the previous copy over a temporary result and requires the complete page hash to match the original, proving that no setting outside the authorized copy changed. Inspection is capped at 100 fields and 64 KiB of source copy to prevent excessive model context.

Responsive requests never replace the section tree:

```json
{
  "schema": "owe-elementor-bridge/1.0",
  "page": "PAGE_ID_OR_LOCAL_URL",
  "device": "mobile",
  "operation": "patch_responsive",
  "expected_page_hash": "CURRENT_HASH",
  "requires_pro": false,
  "target_section_id": "ROOT_SECTION_ID",
  "patches": [
    {
      "element_id": "ELEMENT_ID_INSIDE_TARGET_SECTION",
      "settings": {
        "padding_mobile": {
          "unit": "px",
          "top": "20",
          "right": "20",
          "bottom": "20",
          "left": "20",
          "isLinked": true
        }
      }
    }
  ]
}
```

For tablet every settings key must end in `_tablet`; for mobile it must end in `_mobile`. Base desktop keys are rejected.

Commands:

```bash
bash .opencode/tools/elementor-bridge/bridge.sh check --page "LOCAL_URL_OR_ID"
bash .opencode/tools/elementor-bridge/bridge.sh inspect --page "LOCAL_URL_OR_ID"
bash .opencode/tools/elementor-bridge/bridge.sh inspect-content --page "LOCAL_URL_OR_ID" --section-id "ID"
bash .opencode/tools/elementor-bridge/bridge.sh export-section --page "LOCAL_URL_OR_ID" --section-id "ID"
bash .opencode/tools/elementor-bridge/bridge.sh find-template --slug "EXACT_SLUG"
bash .opencode/tools/elementor-bridge/bridge.sh find-template --name "EXACT_NAME"
bash .opencode/tools/elementor-bridge/bridge.sh inspect-template --template-id "ID"
bash .opencode/tools/elementor-bridge/bridge.sh inspect-attributes --page "LOCAL_URL_OR_ID"
bash .opencode/tools/elementor-bridge/bridge.sh apply --request ".owe/requests/current.json"
```
