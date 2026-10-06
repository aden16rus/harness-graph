<?php
declare(strict_types=1);

namespace Harness\Skills;

interface SkillInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function getParametersSchema(): array;
    public function execute(array $params, SkillExecutionContext $context): SkillResult;
}
