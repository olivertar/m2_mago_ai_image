<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Model\Platform;

use Orangecat\MagoAiImage\Exception\ProviderUnavailableException;
use Symfony\AI\Platform\Bridge\Gemini\Factory as GeminiFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Symfony AI platforms for the image providers, one per request (the key and timeout can differ
 * per store).
 */
class PlatformFactory
{
    public const GEMINI_BASE_URL = 'https://generativelanguage.googleapis.com';
    private const HTTP_URL_PATTERN = '#^https?://#i';

    /**
     * @param HttpClientInterface|null $httpClient Replaces the default client, e.g. in tests
     */
    public function __construct(
        private readonly ?HttpClientInterface $httpClient = null
    ) {
    }

    /**
     * Platform for the Gemini API.
     *
     * @param string $apiKey
     * @param string $baseUrl Without the API version: the bridge appends /v1beta
     * @param int $timeout Seconds
     * @return PlatformInterface
     * @throws ProviderUnavailableException When the base URL is not an http or https URL
     */
    public function createGemini(
        #[\SensitiveParameter] string $apiKey,
        string $baseUrl,
        int $timeout
    ): PlatformInterface {
        $this->assertHttpUrl($baseUrl);

        return GeminiFactory::createPlatform(
            $apiKey,
            $this->createHttpClient($timeout),
            baseUrl: preg_replace('#/v1(beta)?/?$#', '', rtrim($baseUrl, '/')) ?: self::GEMINI_BASE_URL
        );
    }

    /**
     * Platform for the OpenAI API.
     *
     * @param string $apiKey
     * @param int $timeout Seconds
     * @return PlatformInterface
     */
    public function createOpenAi(#[\SensitiveParameter] string $apiKey, int $timeout): PlatformInterface
    {
        return OpenAiFactory::createPlatform($apiKey, $this->createHttpClient($timeout));
    }

    /**
     * Refuse a base URL that is not http or https.
     *
     * The Gemini base URL comes from configuration and the request carries the API key. Another
     * scheme (file://, gopher://) would turn that setting into local file reads or requests to
     * internal services.
     *
     * @param string $baseUrl
     * @return void
     * @throws ProviderUnavailableException
     */
    private function assertHttpUrl(string $baseUrl): void
    {
        if (preg_match(self::HTTP_URL_PATTERN, $baseUrl) !== 1) {
            throw new ProviderUnavailableException(
                __('The Google Gemini base URL must be an http or https URL.')
            );
        }
    }

    /**
     * HTTP client with the configured timeout and no redirects.
     *
     * A provider API answers a request, it never redirects it, and a redirect could carry the key
     * to another host.
     *
     * @param int $timeout Seconds
     * @return HttpClientInterface
     */
    private function createHttpClient(int $timeout): HttpClientInterface
    {
        $options = ['timeout' => $timeout, 'max_duration' => $timeout, 'max_redirects' => 0];

        return $this->httpClient !== null
            ? $this->httpClient->withOptions($options)
            : HttpClient::create($options);
    }
}
