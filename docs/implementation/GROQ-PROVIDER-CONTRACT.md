# Groq provider provisioning contract

## Central licensing response

The client theme does not expose a Groq key editor to store owners or employees. Groq credentials are provisioned by Junction through the central platform and returned only to the matching, domain-bound store installation in the encrypted license-validation payload.

The client expects these optional fields inside the license `data` object:

```json
{
  "limits": {
    "groq_api_keys": 3
  },
  "provider_config": {
    "groq": {
      "model": "the-platform-selected-model",
      "api_keys": [
        "gsk_customer_key_1",
        "gsk_customer_key_2",
        "gsk_customer_key_3"
      ]
    }
  }
}
```

- `limits.groq_api_keys` is the final resolved limit, not a client-side package default. The central service must apply the package maximum and any platform-owner per-customer override before returning it.
- The central dashboard owns the key pool. Customers supply their keys to Junction; Junction provisions them centrally. Keys must not be exposed in store-admin pages, REST responses, logs, email, exports, or browser code.
- The server may return more keys than the resolved limit; the client uses only the first `groq_api_keys` entries. A limit of zero, missing limit, missing model, or empty key list disables Groq for that installation.
- The full license cache is encrypted at rest by the existing LicenseManager. The provider bridge receives the decrypted values in memory only when it needs to call Groq.
- Each installation receives only its own domain-bound configuration. Never return a shared global key pool to all customers.

## Client behavior

- The client uses Groq's OpenAI-compatible chat-completions endpoint and the centrally selected model.
- Requests start at a rotating key position. A rate-limited key (HTTP 429) is cooled down using the provider's `Retry-After` value when available; network failures and HTTP 5xx responses receive shorter cooldowns.
- HTTP 401/403 keys are temporarily disabled for six hours and are logged only by a one-way fingerprint. Raw keys and provider response bodies are never logged.
- The bridge tries other configured keys before returning a safe unavailable response. It does not fall back to an unlicensed provider or invent a model.
- Streaming and non-streaming chat use the same provisioned pool.

## External integration gate

The Junction client code is ready to consume this contract, but the central licensing service/dashboard is outside this repository. Until that service returns the fields above and its deployment is verified, the Groq provider intentionally fails closed as unconfigured. Do not enable production AI chat on a store that has no provisioned key pool and resolved key limit.

- Provider keys and resolved limits refresh from the central license service every five minutes while online, independently of the ordinary 24-hour license cache. If the service is unavailable, the last known-good Groq configuration is usable for at most 24 hours; after that the AI provider fails closed. A 60-second retry backoff prevents repeated license-service calls during an outage.
