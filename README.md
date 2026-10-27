# Orangecat_MagoAiImage

Mago Assistant add-on that creates product images with AI from one of the product's gallery
images and a prompt ("a person walking through the city wearing this jacket"). Images are created
as **previews** and only reach the product gallery when someone attaches them.

One module holds everything: the generation core, the Google Gemini and OpenAI providers, the
`dummy` provider (developer mode only) and the Mago tools. The chat model (e.g. Kimi) only
orchestrates; the image is made by the image provider of the selected AI service.

## Install

```bash
composer require orangecat/module-mago-ai-image
bin/magento module:enable Orangecat_MagoAiImage
bin/magento setup:upgrade
```

Requires `MagoAssistant_Mago` (^1.1) and `MageOS_AiBase` (^1.1).

API keys live only in MageOS_AiBase. Add a Google Gemini or OpenAI service under
`Stores > Configuration > Mage-OS > AI Configuration` first, then select it here.

## Configuration

`Stores > Configuration > Mago Assistant > Image Generation` (`mago_ai_image/*`).

General (`mago_ai_image/general/*`):

| Field | Path | Default |
|---|---|---|
| Enabled | `enabled` | No |
| AI Service | `ai_service` | — (MageOS_AiBase row id, default scope only) |
| Maximum Images per Request | `max_images` | 4 |
| Provider Timeout (seconds) | `timeout` | 90 |
| Maximum Reference Image Size (MB) | `max_reference_mb` | 10 |
| Keep Unattached Previews (days) | `preview_retention_days` | 7 |

The AI Service lists `google` and `openai` rows only ("OpenAI compatible" rows such as Kimi have
no image API). Its API key is used and its code picks the provider: a `google` row uses Gemini, an
`openai` row uses OpenAI. Its chat model is not used. The field is default scope only, because
MageOS_AiBase resolves rows at default scope in the admin, cron and the CLI.

Google Gemini (`mago_ai_image/gemini/*`) and OpenAI (`mago_ai_image/openai/*`):

| Field | Path | Notes |
|---|---|---|
| Model | `model` | Gemini: `gemini-nano-banana-2.1`; OpenAI: `gpt-image-2.5-sunburst` |
| Image Size / Quality | `image_size` (Gemini: 1K/2K/4K), `quality` (OpenAI: low/medium/high) | Empty = model default |
| — | `base_url` | Gemini only, `config.xml` only, without the API version |

The reference image and the prompt are sent to the selected provider.

Upgrading from a version with per-provider credentials: `setup:upgrade` imports a key entered in a
provider group as a MageOS_AiBase row, selects the row of the provider that was selected, and
removes the old `provider`, `credential_source`, `api_key` and `ai_service` settings.

## Providers

Gemini and OpenAI go through the Symfony AI bridges (`symfony/ai-gemini-platform`,
`symfony/ai-open-ai-platform`, `^0.15` like MageOS_AiBase 1.1), built per request by
`Model\Platform\PlatformFactory` with the configured timeout and no redirects. The model is built
from the configured name instead of the bridge catalog, so newer models work without a bridge
release. `Model\Platform\ResponseReader` reads the HTTP status and body before the bridge
converts the result: the bridges drop the provider's error details (`moderation_blocked`,
`promptFeedback`), and the usage is read from the body.

- **Gemini** — `POST {base_url}/v1beta/models/{model}:generateContent` with the key in
  `x-goog-api-key`; one call per image; aspect ratios 1:1, 2:3, 3:2, 3:4, 4:3, 4:5, 5:4, 9:16, 16:9,
  21:9.
- **OpenAI** — `POST https://api.openai.com/v1/images/edits` (multipart, one `image`) with the key
  in `Authorization: Bearer`; the key must start with `sk-`; one call returns `n` images; aspect
  ratios map to sizes near 1.5 MP (multiples of 16); `gpt-image-1*` models only accept 1:1, 3:2 and
  2:3. GPT Image models may require API Organization Verification (a 403 is reported as such).
- **Dummy** — tints the reference image with GD, no network; developer mode only.

Errors are mapped to `ProviderUnavailableException` (key, access, unknown model),
`ContentRejectedException` (safety/moderation), `ProviderTimeoutException` and
`ProviderException` (quota, other).

## Mago tools

| Tool | Access | Magento ACL | Does |
|---|---|---|---|
| `mago_product_image_list` | read | `Magento_Catalog::products` | Gallery images of a SKU, pending previews, supported aspect ratios and image count |
| `mago_product_image_generate` | write | `Orangecat_MagoAiImage::generate` | Generates previews from a gallery image and a prompt; confirmed by the admin, since every image is billed |
| `mago_product_image_attach` | write | `Orangecat_MagoAiImage::attach` | Adds a preview to the product gallery, with roles and label |

The tools are hidden while generation is disabled or no usable AI service is selected. A
call that cannot succeed (no product permission, unknown SKU, empty prompt, preview already attached,
unknown role) is refused before the confirmation card. Previews are served from this store's media URL, so the
chat panel shows them inline (it only loads images from its own origin).

Example questions:

- "Generate an image for WJ12 of a person walking through the city wearing this jacket, 3:4 format"
- "Use the back photo of WJ12 as reference and make two images in a studio with a white background"
- "Add the first one as the base image with the label 'City walk'"

Every returned field is `PiiClass::PUBLIC` (no personal data); `admin_url` is tokenised by Mago.

```bash
bin/magento mago:tool:verify mago_product_image_list '{"sku":"WJ12"}'
```

## CLI and API

```bash
bin/magento mago:ai-image:providers
bin/magento mago:ai-image:generate --sku=WJ12 --prompt="A person walking through the city wearing this jacket" \
    [--provider=gemini] [--media-entry=<gallery id>] [--count=2] [--aspect-ratio=3:4] \
    [--attach --roles=image,thumbnail --label="City"]
```

From code: `Orangecat\MagoAiImage\Api\ImageGeneratorInterface::generateForProduct()` returns
previews (`GenerationInterface`), `GenerationManagementInterface::attach()` adds one to the gallery
and `discard()` drops it. Previews are stored in `pub/media/mago_ai_image/preview/`. The
`mago_ai_image_cleanup_previews` cron job removes them after the retention period: unattached
ones are discarded, attached ones only lose the preview file (the gallery keeps its own copy, and
`file` is set to empty). Until then the Mago chat history keeps showing them. Generations are
kept in `mago_ai_image_generation`, with the admin who requested them.

ACL: `Orangecat_MagoAiImage::generate`, `Orangecat_MagoAiImage::attach` (under Catalog > Inventory >
Products) and `Orangecat_MagoAiImage::config`. The generate and attach tools also require
`Magento_Catalog::products` at run time, so a role cannot change galleries of products it cannot edit.

## Adding a provider from another module

1. Implement `Orangecat\MagoAiImage\Api\ImageProviderInterface` and add it to the `providers`
   argument of `Orangecat\MagoAiImage\Model\ProviderPool` in `di.xml`.
2. Return the MageOS_AiBase codes the provider serves from `getAiBaseServiceCodes()`, and add
   them to the `serviceCodes` argument of the `Orangecat\MagoAiImage\Model\Config\Source\ImageAiService`
   virtual type in your `di.xml`, so the AI Service dropdown offers those rows.
3. Add a group with the provider code to the `mago_ai_image` section in
   `etc/adminhtml/system.xml`, like the `gemini` group: `model` and any provider-specific
   options. No key fields: the key comes from the selected MageOS_AiBase row.
4. Read settings only through `ProviderConfigInterface` (`getApiKey()`, `getModel()`,
   `getValue()`). Never log or return the key.
5. Use `Model\Config::getTimeout()` as the HTTP timeout and throw the exceptions above with
   messages fit for an administrator. For a provider with a Symfony AI bridge, follow
   `Model\Provider\Gemini`: a platform from the bridge factory, the model built from its name, and
   `Model\Platform\ResponseReader` to check the response before converting it.

## Privacy

The reference image and the prompt are sent to the configured provider (Google, OpenAI). The admin
who generated each preview is stored in `mago_ai_image_generation.admin_user_id`.

## License

MIT, see [LICENSE](LICENSE).
