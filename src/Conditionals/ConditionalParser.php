<?php

namespace EduLazaro\Larablog\Conditionals;

use League\CommonMark\Extension\CommonMark\Parser\Block\FencedCodeParser;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Cursor;

final class ConditionalParser extends AbstractBlockContinueParser
{
    private ConditionalBlock $block;

    public function __construct(string $post)
    {
        $this->block = new ConditionalBlock($post);
    }

    public function getBlock(): ConditionalBlock
    {
        return $this->block;
    }

    public function isContainer(): bool
    {
        return true;
    }

    public function canContain(AbstractBlock $childBlock): bool
    {
        return true;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        if (! $cursor->isIndented() && trim($cursor->getRemainder()) === ':::' && ! $activeBlockParser instanceof FencedCodeParser) {
            // The closing marker belongs to the innermost conditional container.
            for ($node = $activeBlockParser->getBlock(); $node !== null && $node !== $this->block; $node = $node->parent()) {
                if ($node instanceof ConditionalBlock) {
                    return BlockContinue::at($cursor);
                }
            }

            $this->block->closed = true;

            return BlockContinue::finished();
        }

        return BlockContinue::at($cursor);
    }
}
