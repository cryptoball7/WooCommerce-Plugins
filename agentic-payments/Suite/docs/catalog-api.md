# Agent Catalog API (v1)

This document defines the **Agent Catalog & Discovery API**, the first agent-facing surface of the Agent Commerce ecosystem.

The Catalog API is **read-only**, deterministic, and safe by default. It allows AI agents to discover and evaluate products without mutating WooCommerce state.

---

## Design goals

- Enable agents to discover products safely
- Provide deterministic, cacheable responses
- Avoid exposing WooCommerce internals
- Scale from simple stores to large catalogs
- Work consistently across agent protocols

---

## Namespace and versioning

All endpoints live under:

```
/wp-json/agent-commerce/v1/catalog
```

Breaking changes require a new API version.

---

## Required request envelope

Every request **must include**:

- `agent_identity` (schema: `agent_identity`)
- `agent_context` (schema: `agent_context`)

Requests missing either object must be rejected.

---

## Authentication & authorization

- Authorization is enforced by Agent Core
- Catalog endpoints require the scope:

```
catalog:read
```

Endpoints do not infer permissions from identity or metadata.

---

## Endpoint: List products

### `GET /products`

Returns a paginated list of products visible to agents.

### Query parameters

| Parameter | Type | Description |
|---------|------|-------------|
| `page` | integer | Page number (default: 1) |
| `per_page` | integer | Items per page (default: 20, max: 100) |
| `search` | string | Free-text search query |
| `status` | string | Filter by product status (`active` only by default) |
| `in_stock` | boolean | Filter by availability |
| `min_price` | number | Minimum price filter |
| `max_price` | number | Maximum price filter |

Filtering behavior must be deterministic.

---

### Response (200)

```json
{
  "products": [
    { /* product_summary */ }
  ],
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total_items": 134,
    "total_pages": 7
  }
}
```

- Each item in `products` conforms to `product_summary`
- Ordering must be stable across identical requests

---

## Endpoint: Get product by ID

### `GET /products/{product_id}`

Returns a single product summary.

### Path parameters

| Parameter | Type | Description |
|---------|------|-------------|
| `product_id` | string | Stable product identifier |

---

### Response (200)

```json
{
  "product": { /* product_summary */ }
}
```

---

### Response (404)

Returned when:
- The product does not exist
- The product exists but is not visible to agents

---

## Endpoint: List product variations (optional)

### `GET /products/{product_id}/variations`

Returns variations for a variable product.

- Only applicable when `type = variable`
- Variations are returned as `product_summary` objects

---

## Error handling

All errors follow a consistent shape:

```json
{
  "error": {
    "code": "invalid_request",
    "message": "Human-readable explanation",
    "details": { }
  }
}
```

### Common error codes

| Code | Meaning |
|-----|--------|
| `unauthorized` | Missing or invalid credentials |
| `forbidden` | Missing required scope |
| `invalid_request` | Schema validation failed |
| `not_found` | Resource not found |
| `internal_error` | Unexpected server error |

Errors must be deterministic and must not leak WooCommerce internals.

---

## Determinism rules

Catalog responses must:

- Be identical for identical inputs
- Not depend on session state
- Not depend on request timing
- Not perform side effects

If the underlying catalog changes, responses may change accordingly.

---

## Caching guidance

Catalog endpoints are safe to cache when:

- `execution_mode = preview` or `dry_run`
- No authenticated merchant context is involved

Cache headers may be added by Agent Core or upstream infrastructure.

---

## Explicit non-goals

The Catalog API does **not**:

- Create carts or orders
- Reserve inventory
- Calculate taxes or shipping
- Personalize results
- Expose upsells or recommendations

These concerns belong to other plugins or schemas.

---

## Relationship to WooCommerce

WooCommerce is treated as an **implementation detail**.

- Product visibility rules are respected
- Internal IDs may be mapped but not exposed
- Changes in WooCommerce APIs must not affect this contract

---

## Exit criteria for v1

The Catalog API is considered stable when:

- Responses validate against schemas
- Pagination is deterministic
- Error shapes are consistent
- No WooCommerce objects are exposed

Once stable, other plugins may safely depend on it.

