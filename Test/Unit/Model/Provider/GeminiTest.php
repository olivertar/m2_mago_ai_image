<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Unit\Model\Provider;

use Orangecat\MagoAiImage\Api\Data\ImageBinaryInterfaceFactory;
use Orangecat\MagoAiImage\Api\Data\ImageResultInterfaceFactory;
use Orangecat\MagoAiImage\Api\Data\ProviderCapabilitiesInterfaceFactory;
use Orangecat\MagoAiImage\Api\ProviderConfigInterface;
use Orangecat\MagoAiImage\Exception\ContentRejectedException;
use Orangecat\MagoAiImage\Exception\ProviderException;
use Orangecat\MagoAiImage\Exception\ProviderUnavailableException;
use Orangecat\MagoAiImage\Model\Config;
use Orangecat\MagoAiImage\Model\Data\ImageBinary;
use Orangecat\MagoAiImage\Model\Data\ImageRequest;
use Orangecat\MagoAiImage\Model\Data\ImageResult;
use Orangecat\MagoAiImage\Model\Data\ProviderCapabilities;
use Orangecat\MagoAiImage\Exception\ProviderTimeoutException;
use Orangecat\MagoAiImage\Model\Platform\PlatformFactory;
use Orangecat\MagoAiImage\Model\Platform\ResponseReader;
use Orangecat\MagoAiImage\Model\Provider\Gemini;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[AllowMockObjectsWithoutExpectations]
class GeminiTest extends TestCase
{
    /**
     * @var array<string, string|null>
     */
    private array $values = [];

    /**
     * @var string
     */
    private string $model = Gemini::DEFAULT_MODEL;

    /**
     * Responses (or callables producing them) served in order; every request is recorded.
     *
     * @var array<int, MockResponse|callable>
     */
    private array $responses = [];

    /**
     * @var array<int, array{method: string, url: string, options: mixed[]}>
     */
    private array $requests = [];

    /**
     * @var Gemini
     */
    private Gemini $gemini;

    protected function setUp(): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $response = array_shift($this->responses) ?? $this->json($this->imageResponse('X'));

            return is_callable($response) ? $response() : $response;
        });

        $providerConfig = $this->createStub(ProviderConfigInterface::class);
        $providerConfig->method('getApiKey')->willReturn('secret-key');
        $providerConfig->method('hasApiKey')->willReturn(true);
        $providerConfig->method('getModel')->willReturnCallback(fn (): string => $this->model);
        $providerConfig->method('getValue')->willReturnCallback(
            fn ($provider, string $field) => $this->values[$field] ?? null
        );

        $config = $this->createStub(Config::class);
        $config->method('getTimeout')->willReturn(60);

        $capabilities = $this->createStub(ProviderCapabilitiesInterfaceFactory::class);
        $capabilities->method('create')->willReturnCallback(
            static fn (array $data): ProviderCapabilities => new ProviderCapabilities(...$data)
        );
        $binaries = $this->createStub(ImageBinaryInterfaceFactory::class);
        $binaries->method('create')->willReturnCallback(
            static fn (array $data): ImageBinary => new ImageBinary(...$data)
        );
        $results = $this->createStub(ImageResultInterfaceFactory::class);
        $results->method('create')->willReturnCallback(
            static fn (array $data): ImageResult => new ImageResult(...$data)
        );

        $this->gemini = new Gemini(
            new PlatformFactory($http),
            new ResponseReader(),
            $providerConfig,
            $config,
            $capabilities,
            $binaries,
            $results
        );
    }

    public function testBuildsRequestAndParsesImage(): void
    {
        $this->values['image_size'] = '2K';
        $this->responses[] = $this->json($this->imageResponse('PNGDATA', 'Here is your image'));

        $reference = new ImageBinary('JPEGDATA', 'image/jpeg');
        $result = $this->gemini->generate(new ImageRequest('carry this backpack', [$reference], '3:4', 1, 7));

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-nano-banana-2.1:generateContent',
            $request['url']
        );
        $this->assertContains('x-goog-api-key: secret-key', $request['options']['headers']);
        $this->assertSame(
            [
                'generationConfig' => [
                    'responseModalities' => ['TEXT', 'IMAGE'],
                    'imageConfig' => ['aspectRatio' => '3:4', 'imageSize' => '2K'],
                    'seed' => 7,
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['text' => 'carry this backpack'],
                        ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode('JPEGDATA')]],
                    ],
                ]],
            ],
            $this->body(0)
        );

        $this->assertCount(1, $result->getImages());
        $this->assertSame('PNGDATA', $result->getImages()[0]->getContent());
        $this->assertSame('image/png', $result->getImages()[0]->getMimeType());
        $this->assertSame('Here is your image', $result->getRevisedPrompt());
        $this->assertSame('gemini-nano-banana-2.1', $result->getModel());
        $this->assertSame(1, $result->getUsage()['images']);
        $this->assertSame(1300, $result->getUsage()['total_tokens']);
    }

    public function testVersionedBaseUrlIsNormalized(): void
    {
        $this->values['base_url'] = 'https://proxy.example.com/v1beta/';

        $this->gemini->generate(new ImageRequest('prompt'));

        $this->assertStringStartsWith('https://proxy.example.com/v1beta/models/', $this->requests[0]['url']);
    }

    public function testItRefusesABaseUrlThatIsNotHttp(): void
    {
        $this->values['base_url'] = 'file:///etc/passwd';

        $this->expectException(ProviderUnavailableException::class);
        $this->gemini->generate(new ImageRequest('prompt'));
    }

    public function testOmitsImageConfigWhenNothingRequested(): void
    {
        $this->gemini->generate(new ImageRequest('prompt'));

        $this->assertSame(['responseModalities' => ['TEXT', 'IMAGE']], $this->body(0)['generationConfig']);
    }

    public function testOneCallPerRequestedImage(): void
    {
        $result = $this->gemini->generate(new ImageRequest('prompt', [], null, 3));

        $this->assertCount(3, $this->requests);
        $this->assertCount(3, $result->getImages());
        $this->assertSame(3, $result->getUsage()['calls']);
    }

    public function testItKeepsImagesOfEarlierCallsWhenALaterCallFails(): void
    {
        $this->responses[] = $this->json($this->imageResponse('first'));
        $this->responses[] = $this->json($this->imageResponse('second'));
        $this->responses[] = $this->json(
            ['error' => ['code' => 429, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED']],
            429
        );

        $result = $this->gemini->generate(new ImageRequest('prompt', [], null, 3));

        $this->assertCount(2, $result->getImages());
        $this->assertSame(2, $result->getUsage()['calls']);
    }

    public function testThoughtImagesAreSkipped(): void
    {
        $body = $this->imageResponse('FINAL');
        array_unshift($body['candidates'][0]['content']['parts'], [
            'thought' => true,
            'inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('DRAFT')],
        ]);
        $this->responses[] = $this->json($body);

        $images = $this->gemini->generate(new ImageRequest('prompt'))->getImages();

        $this->assertCount(1, $images);
        $this->assertSame('FINAL', $images[0]->getContent());
    }

    public function testBlockedPromptIsRejection(): void
    {
        $this->responses[] = $this->json(['promptFeedback' => ['blockReason' => 'SAFETY']]);

        $this->expectException(ContentRejectedException::class);
        $this->gemini->generate(new ImageRequest('prompt'));
    }

    public function testSafetyFinishWithoutImageIsRejection(): void
    {
        $this->responses[] = $this->json([
            'candidates' => [['finishReason' => 'IMAGE_SAFETY', 'content' => ['parts' => []]]],
        ]);

        $this->expectException(ContentRejectedException::class);
        $this->gemini->generate(new ImageRequest('prompt'));
    }

    public function testTextOnlyAnswerReportsTheText(): void
    {
        $this->responses[] = $this->json([
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'I cannot draw that']]]]],
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('I cannot draw that');
        $this->gemini->generate(new ImageRequest('prompt'));
    }

    public function testInvalidKeyIsUnavailable(): void
    {
        $this->responses[] = $this->json(['error' => [
            'code' => 400,
            'message' => 'API key not valid. Please pass a valid API key.',
            'status' => 'INVALID_ARGUMENT',
        ]], 400);

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionMessage('API key not valid');
        $this->gemini->generate(new ImageRequest('prompt'));
    }

    public function testQuotaError(): void
    {
        $this->responses[] = $this->json(
            ['error' => ['code' => 429, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED']],
            429
        );

        $this->expectExceptionMessage('quota or rate limit');
        $this->gemini->generate(new ImageRequest('prompt'));
    }

    public function testTimeout(): void
    {
        // An empty chunk makes MockResponse report an idle timeout.
        $this->responses[] = new MockResponse((static function (): \Generator {
            yield '';
        })());

        $this->expectException(ProviderTimeoutException::class);
        $this->gemini->generate(new ImageRequest('prompt'));
    }

    public function testModelNameCannotAlterPath(): void
    {
        $this->model = '../files?x=1';

        try {
            $this->gemini->generate(new ImageRequest('prompt'));
            $this->fail('Expected ProviderUnavailableException');
        } catch (ProviderUnavailableException) {
            $this->assertSame([], $this->requests);
        }
    }

    public function testDeclaresGoogleAiBaseRows(): void
    {
        $this->assertSame(['google'], $this->gemini->getAiBaseServiceCodes());
        $this->assertTrue($this->gemini->isAvailable());
        $this->assertContains('3:4', $this->gemini->getCapabilities()->getSupportedAspectRatios());
    }

    /**
     * @return mixed[]
     */
    private function imageResponse(string $bytes, string $text = ''): array
    {
        $parts = [];
        if ($text !== '') {
            $parts[] = ['text' => $text];
        }
        $parts[] = ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($bytes)]];

        return [
            'candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => $parts]]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 1290, 'totalTokenCount' => 1300],
        ];
    }

    /**
     * @param int $index
     * @return mixed[]
     */
    private function body(int $index): array
    {
        return json_decode((string) $this->requests[$index]['options']['body'], true);
    }

    /**
     * @param mixed[] $body
     * @param int $status
     * @return MockResponse
     */
    private function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(
            (string) json_encode($body),
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]
        );
    }
}
