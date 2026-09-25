<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Llm;

use Alashqar\Lazarus\Llm\Contracts\LlmDriver;
use Alashqar\Lazarus\Llm\Drivers\AnthropicDriver;
use Alashqar\Lazarus\Llm\Drivers\DriverConfig;
use Alashqar\Lazarus\Llm\Drivers\FakeDriver;
use Alashqar\Lazarus\Llm\Drivers\OpenAiDriver;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Manager;

/**
 * Resolves the configured LLM driver. Custom drivers can be added with extend().
 *
 * @method LlmDriver driver(string|null $driver = null)
 */
final class LlmManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $driver = $this->config->get('lazarus.llm.driver', 'anthropic');

        return is_string($driver) ? $driver : 'anthropic';
    }

    protected function createAnthropicDriver(): LlmDriver
    {
        return new AnthropicDriver($this->http(), $this->driverConfig('anthropic'));
    }

    protected function createOpenaiDriver(): LlmDriver
    {
        return new OpenAiDriver($this->http(), $this->driverConfig('openai'));
    }

    protected function createFakeDriver(): LlmDriver
    {
        return new FakeDriver;
    }

    private function http(): Factory
    {
        return $this->container->make(Factory::class);
    }

    private function driverConfig(string $name): DriverConfig
    {
        $values = $this->config->get('lazarus.llm.drivers.'.$name, []);

        return new DriverConfig(is_array($values) ? $values : []);
    }
}
