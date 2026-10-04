<?php

namespace EduLazaro\Larablog\Exceptions;

use RuntimeException;

/**
 * A post file that cannot be read: broken frontmatter, no title, a slug used twice.
 */
class InvalidPost extends RuntimeException
{
}
