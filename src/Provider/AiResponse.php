<?php

namespace Tag1\Scolta\Provider;

class AiResponse {
    public function __construct(
        public readonly string $content,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly string $model = '',
    ) {}
}
