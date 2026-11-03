<?php

namespace EduLazaro\Larablog\Conditionals;

use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Renderer\Block\ListItemRenderer;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class ConditionalRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string|\Stringable|null
    {
        if ($node instanceof ListItem) {
            $condition = $node->firstChild();
            if (! $condition instanceof ConditionalBlock || $condition->next() !== null) {
                return null;
            }

            // Surround the whole li, so a hidden condition leaves no empty bullet behind.
            return $this->open($condition) . (new ListItemRenderer())->render($node, $childRenderer) . '<!--larablog:endif-->';
        }

        ConditionalBlock::assertInstanceOf($node);
        $content = $childRenderer->renderNodes($node->children());
        if ($node->parent() instanceof ListItem && $node->previous() === null && $node->next() === null) {
            return $content;
        }

        return $this->open($node) . $content . '<!--larablog:endif-->';
    }

    private function open(ConditionalBlock $block): string
    {
        return '<!--larablog:if-published:' . $block->post . ':' . (int) $block->closed . '-->';
    }
}
