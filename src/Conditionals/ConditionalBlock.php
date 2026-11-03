<?php

namespace EduLazaro\Larablog\Conditionals;

use League\CommonMark\Node\Block\AbstractBlock;

final class ConditionalBlock extends AbstractBlock
{
    public bool $closed = false;

    public function __construct(public readonly string $post)
    {
        parent::__construct();
    }
}
