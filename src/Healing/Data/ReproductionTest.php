<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing\Data;

use Alashqar\Lazarus\Llm\Contracts\StructuredResponse;
use Alashqar\Lazarus\Llm\InvalidLlmResponse;
use Alashqar\Lazarus\Llm\Payload;

final readonly class ReproductionTest implements StructuredResponse
{
    public function __construct(
        public string $path,
        public string $content,
        public string $reasoning = '',
    ) {}

    public static function schema(): string
    {
        return <<<'JSON'
        {
          "path": "tests/Feature/Lazarus/SomethingDescriptiveTest.php",
          "content": "<?php ... the complete test file ...",
          "reasoning": "one or two sentences on how the test triggers the bug"
        }
        JSON;
    }

    public static function fromLlm(array $data): static
    {
        $path = ltrim(str_replace('\\', '/', Payload::string($data, 'path')), '/');
        $content = self::withoutFences(Payload::string($data, 'content'));

        if (! str_starts_with($path, 'tests/') || ! str_ends_with($path, 'Test.php')) {
            throw new InvalidLlmResponse('"path" must be a new file under tests/ whose name ends in Test.php.');
        }

        if (! str_starts_with(ltrim($content), '<?php')) {
            throw new InvalidLlmResponse('"content" must be a complete PHP file starting with <?php.');
        }

        return new self($path, $content, is_string($data['reasoning'] ?? null) ? $data['reasoning'] : '');
    }

    /**
     * Small models often wrap the file in Markdown fences even inside JSON; a stray ``` is a parse error.
     */
    private static function withoutFences(string $content): string
    {
        $content = preg_replace('/^\s*```[a-z]*[ \t]*\R/i', '', $content) ?? $content;

        return (preg_replace('/\R\s*```\s*$/', '', $content) ?? $content)."\n";
    }
}
