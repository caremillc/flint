# Flint HTTP contract

## Greeting styles

Both greeting endpoints support these case-sensitive styles:

| Style | Greeting |
|---|---|
| `friendly` | `Hello, {name}` |
| `formal` | `Welcome, {name}` |

Omitting the style selects `friendly`.

## GET /hello/{name}

Returns a JSON greeting.

Example:

```http
GET /hello/Ada?style=formal
```

```json
{"greeting":"Welcome, Ada"}
```

Behavior:

- The name occupies one non-empty route segment.
- Route captures retain their encoded representation.
- `/hello/a%2Fb` produces `Hello, a%2Fb`.
- Unknown query parameters are ignored by this handler.
- An empty or unsupported style returns plain-text `400 Bad request`.
- Malformed query structure is rejected by query middleware before
  the handler runs.
- Duplicate decoded query names are rejected, including aliases such
  as `name` and `%6Eame`.
- HEAD uses the corresponding GET response status and headers,
  with no response body.

## POST /hello

Accepts a JSON object and returns a JSON greeting.

Example:

```http
POST /hello
Content-Type: application/json

{"name":"Ada","style":"formal"}
```

```json
{"greeting":"Welcome, Ada"}
```

### Media type

- Exactly one Content-Type value is required.
- The media type must be `application/json`, matched case-insensitively.
- Media-type parameters are not interpreted by the handler.
- JSON input must still be valid UTF-8.
- Any Content-Encoding header is rejected by the handler.
- Unsupported media types return `415`.

### Fields

| Field | Required | Rules |
|---|---|---|
| `name` | Yes | JSON string, non-blank under PHP `trim()`, at most 100 UTF-8 bytes |
| `style` | No | JSON string containing `friendly` or `formal` |

Additional rules:

- Unknown fields are rejected.
- Explicit null is invalid for either field.
- Name whitespace is validated but is not removed from the greeting.
- Numeric names are rejected, including integers larger than PHP's
  native integer range.
- Quoted digit strings are valid names.
- The 100-byte name limit is not a character or grapheme limit.
- Duplicate JSON object members currently follow PHP decoding behavior:
  the last member wins. They are not separately rejected.

### Error responses

Malformed JSON or a non-object JSON root returns `400`:

```json
{
  "type": "about:blank",
  "title": "Bad Request",
  "status": 400,
  "detail": "The body must contain a valid JSON object."
}
```

Invalid fields return `422`:

```json
{
  "type": "about:blank",
  "title": "Unprocessable Content",
  "status": 422,
  "invalid_fields": ["name", "style"]
}
```

Unsupported media types return `415`:

```json
{
  "type": "about:blank",
  "title": "Unsupported Media Type",
  "status": 415,
  "detail": "Use application/json without Content-Encoding."
}
```

These handler-generated errors use `application/problem+json`.

`invalid_fields` is a Flint-specific extension. It contains field names,
not submitted values. The ordering is:

1. `name`, if invalid.
2. `style`, if invalid.
3. Unknown fields in input order.

## Processing boundaries

Request capture occurs before application middleware.

Capture rejection can therefore occur before a request reaches routing
or the greeting handler. Examples include oversized request bodies and
unsupported multipart capture.

Query middleware also runs before the greeting handler. A request with
both duplicate query parameters and malformed JSON receives the query
middleware's plain-text `400` response.

Problem Details is currently used by the JSON greeting handler.
Routing, capture, query rejection, and bootstrap failure responses
retain their existing response formats.

## Response policy and logging

Responses passing through the application middleware receive:

- A generated X-Request-ID.
- X-Content-Type-Options: nosniff.
- A structured request-completion log record.

Incoming request IDs are replaced.

Capture and bootstrap failures occur outside this middleware path.
They do not receive middleware-generated request IDs or completion
records. The entry point applies the response security policy and
reports bootstrap failures separately.

HEAD responses contain no body bytes. Where applicable, Content-Length
describes the corresponding representation.

## Verification

Run the complete development checks from the application root:

```bash
composer check:all
```

Focused application checks:

```bash
composer test:greeting
composer test:json-greeting-input
composer test:json-greeting
composer test:json-greeting-bootstrap
composer test:http-application
composer test:http-failure
```

This increment changes documentation only. Expected totals remain 439 framework tests, 38 correlated HTTP responses, and 43 application HTTP requests.
