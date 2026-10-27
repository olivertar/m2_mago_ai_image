<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Unit\Model\Provider;

use Orangecat\MagoAiImage\Api\Data\ImageBinaryInterface;
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
use Orangecat\MagoAiImage\Model\Platform\PlatformFactory;
use Orangecat\MagoAiImage\Model\Platform\ResponseReader;
use Orangecat\MagoAiImage\Model\Provider\OpenAi;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[AllowMockObjectsWithoutExpectations]
class OpenAiTest extends TestCase
{
    /**
     * @var array<string, string|null>
     */
    private array $values = [];

    /**
     * @var string
     */
    private string $model = OpenAi::DEFAULT_MODEL;

    /**
     * @var string
     */
    private string $apiKey = 'sk-secret';

    /**
     * Responses served in order; every request is recorded.
     *
     * @var MockResponse[]
     */
    private array $responses = [];

    /**
     * @var array<int, array{method: string, url: string, options: mixed[]}>
     */
    private array $requests = [];

    /**
     * @var OpenAi
     */
    private OpenAi $openAi;

    protected function setUp(): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($this->responses) ?? $this->json(['data' => [['b64_json' => base64_encode('X')]]]);
        });

        $providerConfig = $this->createStub(ProviderConfigInterface::class);
        $providerConfig->method('getApiKey')->willReturnCallback(fn (): string => $this->apiKey);
        $providerConfig->method('hasApiKey')->willReturn(true);
        $providerConfig->method('getModel')->willReturnCallback(fn (): string => $this->model);
        $providerConfig->method('getValue')->willReturnCallback(
            fn ($provider, string $field) => $this->values[$field] ?? null
        );

        $config = $this->createStub(Config::class);
        $config->method('getTimeout')->willReturn(90);

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

        $this->openAi = new OpenAi(
            new PlatformFactory($http),
            new ResponseReader(),
            $providerConfig,
            $config,
            $capabilities,
            $binaries,
            $results
        );
    }

    public function testBuildsEditRequestAndParsesImages(): void
    {
        $this->values['quality'] = 'high';
        $this->responses[] = $this->json([
            'data' => [['b64_json' => base64_encode('IMG1')], ['b64_json' => base64_encode('IMG2')]],
            'usage' => ['input_tokens' => 400, 'output_tokens' => 3000, 'total_tokens' => 3400],
        ]);

        $reference = new ImageBinary('JPEGDATA', 'image/jpeg');
        $result = $this->openAi->generate(new ImageRequest('wear this jacket', [$reference], '3:4', 2));

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.openai.com/v1/images/edits', $request['url']);
        $this->assertContains(
            'Authorization: Bearer sk-secret',
            $request['options']['normalized_headers']['authorization']
        );
        $this->assertSame(
            [
                'n' => '2',
                'size' => '1152x1536',
                'quality' => 'high',
                'model' => 'gpt-image-2.5-sunburst',
                'prompt' => 'wear this jacket',
            ],
            $this->fields(0)
        );
        $this->assertStringContainsString(
            "name=\"image\"; filename=\"image.jpeg\"\r\nContent-Type: image/jpeg\r\n\r\nJPEGDATA\r\n",
            (string) $request['options']['body']
        );

        $this->assertSame(['IMG1', 'IMG2'], array_map(
            static fn (ImageBinaryInterface $image): string => $image->getContent(),
            $result->getImages()
        ));
        $this->assertSame('gpt-image-2.5-sunburst', $result->getModel());
        $this->assertSame(
            ['images' => 2, 'calls' => 1, 'input_tokens' => 400, 'output_tokens' => 3000, 'total_tokens' => 3400],
            $result->getUsage()
        );
    }

    public function testOmitsSizeAndQualityByDefault(): void
    {
        $this->openAi->generate(new ImageRequest('p', [new ImageBinary('R', 'image/png')]));

        $this->assertSame(['n' => '1', 'model' => OpenAi::DEFAULT_MODEL, 'prompt' => 'p'], $this->fields(0));
    }

    public function testMalformedKeyIsUnavailable(): void
    {
        $this->apiKey = 'not-an-openai-key';

        try {
            $this->openAi->generate(new ImageRequest('p', [new ImageBinary('R', 'image/png')]));
            $this->fail('Expected ProviderUnavailableException');
        } catch (ProviderUnavailableException) {
            $this->assertSame([], $this->requests);
        }
    }

    public function testLegacyModelsOnlyOfferThreeRatios(): void
    {
        $this->assertCount(10, $this->openAi->getCapabilities()->getSupportedAspectRatios());

        $this->model = 'gpt-image-1.5';
        $this->assertSame(['1:1', '3:2', '2:3'], $this->openAi->getCapabilities()->getSupportedAspectRatios());

        $this->expectException(ProviderException::class);
        $this->openAi->generate(new ImageRequest('p', [new ImageBinary('R', 'image/png')], '16:9'));
    }

    public function testNeedsReferenceImage(): void
    {
        $this->expectException(ProviderException::class);

        $this->openAi->generate(new ImageRequest('p'));
    }

    public function testRejectsUnsafeModelName(): void
    {
        $this->model = 'gpt"image';
        $this->expectException(ProviderUnavailableException::class);

        $this->openAi->generate(new ImageRequest('p', [new ImageBinary('R', 'image/png')]));
    }

    /**
     * @return array<string, array{int, mixed[], class-string}>
     */
    public static function errorProvider(): array
    {
        return [
            'moderation' => [400, ['error' => [
                'type' => 'image_generation_user_error',
                'code' => 'moderation_blocked',
                'message' => 'Blocked.',
            ]], ContentRejectedException::class],
            'bad key' => [
                401,
                ['error' => ['message' => 'Incorrect API key provided']],
                ProviderUnavailableException::class,
            ],
            'not verified' => [
                403,
                ['error' => ['message' => 'Organization must be verified']],
                ProviderUnavailableException::class,
            ],
            'unknown model' => [404, ['error' => ['code' => 'model_not_found']], ProviderUnavailableException::class],
            'quota' => [429, ['error' => ['code' => 'insufficient_quota']], ProviderException::class],
            'server' => [500, [], ProviderException::class],
            'no image' => [200, ['data' => []], ProviderException::class],
        ];
    }

    /**
     * @param int $status
     * @param mixed[] $body
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('errorProvider')]
    public function testMapsErrors(int $status, array $body, string $exception): void
    {
        $this->responses[] = $this->json($body, $status);
        $this->expectException($exception);

        $this->openAi->generate(new ImageRequest('p', [new ImageBinary('R', 'image/png')]));
    }

    /**
     * Scalar form fields of a multipart request, in order.
     *
     * @param int $index
     * @return array<string, string>
     */
    private function fields(int $index): array
    {
        preg_match_all(
            '/name="([^"]+)"\r\n\r\n([^\r]*)\r\n/',
            (string) $this->requests[$index]['options']['body'],
            $matches,
            PREG_SET_ORDER
        );

        return array_column($matches, 2, 1);
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
