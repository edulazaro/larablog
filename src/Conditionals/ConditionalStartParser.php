<?php

namespace EduLazaro\Larablog\Conditionals;

use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;

final class ConditionalStartParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented()) {
            return BlockStart::none();
        }

        $cursor->advanceToNextNonSpaceOrTab();

        if (! preg_match('/^:::[ \t]+if-published[ \t]+post=([\'\"])([A-Za-z0-9_.-]+)\1[ \t]*$/', $cursor->getRemainder(), $match)) {
            return BlockStart::none();
        }

        $cursor->advanceToEnd();

        return BlockStart::of(new ConditionalParser($match[2]))->at($cursor);
    }
}
