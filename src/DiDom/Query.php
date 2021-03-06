<?php

namespace DiDom;

use InvalidArgumentException;
use RuntimeException;

class Query
{
    /**
     * Types of expressions.
     * 
     * @string
     */
    const TYPE_XPATH = 'XPATH';
    const TYPE_CSS   = 'CSS';

    /**
     * @var array
     */
    protected static $compiled = array();

    /**
     * Transform CSS expression to XPath.
     *
     * @param  string $expression XPath expression or CSS selector
     * @param  string $type       the type of the expression
     * @return string
     */
    public static function compile($expression, $type = self::TYPE_CSS)
    {
        if (strcasecmp($type, self::TYPE_XPATH) == 0) {
            return $expression;
        }

        $selectors = explode(',', $expression);
        $paths = [];

        foreach ($selectors as $selector) {
            $selector = trim($selector);

            if (array_key_exists($selector, static::$compiled)) {
                $paths[] = static::$compiled[$selector];
                continue;
            }

            static::$compiled[$selector] = static::cssToXpath($selector);

            $paths[] = static::$compiled[$selector];
        }

        return implode('|', $paths);
    }

    /**
     * @param  string $selector
     * @param  string $prefix
     * @return string
     */
    public static function cssToXpath($selector, $prefix = '//')
    {
        $xpath    = '';
        $segments = self::getSegments($selector);

        while (count($segments) > 0) {
            $xpath .= self::buildXpath($segments, $prefix);

            $selector = trim(substr($selector, strlen($segments['selector'])));
            $prefix   = (isset($segments['rel'])) ? '/' : '//';

            if ($selector === '') {
                break;
            }

            $segments = self::getSegments($selector);
        }

        return $xpath;
    }

    /**
     * @param  array  $segments
     * @param  string $prefix
     * @return string
     */
    public static function buildXpath($segments, $prefix = '//')
    {
        $attributes = array();

        // if the id attribute specified
        if (isset($segments['id'])) {
            $attributes[] = "@id='".$segments['id']."'";
        }

        // if the attributes specified
        if (isset($segments['attributes'])) {
            foreach ($segments['attributes'] as $name => $value) {
                // if specified only the attribute name
                $attributes[] = '@'.$name.($value == null ? '' : '="'.$value.'"');
            }
        }

        // if the class attribute specified
        if (isset($segments['classes'])) {
            foreach ($segments['classes'] as $class) {
                $attributes[] = 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
            }
        }

        // if the pseudo class specified
        if (isset($segments['pseudo'])) {
            $expression   = isset($segments['expr']) ? $segments['expr'] : '';
            $attributes[] = self::convertPseudo($segments['pseudo'], $expression);
        }

        $xpath = $prefix.$segments['tag'];

        if ($count = count($attributes)) {
            $xpath .= ($count > 1) ? '[('.implode(') and (', $attributes).')]' : '['.implode(' and ', $attributes).']';
        }

        return $xpath;
    }

    /**
     * @param  string $pseudo
     * @param  string $expression
     * @return string
     * @throws \RuntimeException if passed an unknown pseudo-class
     */
    protected static function convertPseudo($pseudo, $expression)
    {
        if ('first-child' === $pseudo) {
            return '1';
        } elseif ('last-child' === $pseudo) {
            return 'last()';
        } elseif ('nth-child' === $pseudo) {
            if ('' !== $expression) {
                if ('odd' === $expression) {
                    return '(position() -1) mod 2 = 0 and position() >= 1';
                } elseif ('even' === $expression) {
                    return 'position() mod 2 = 0 and position() >= 0';
                } elseif (is_numeric($expression)) {
                    return 'position() = '.$expression;
                } elseif (preg_match("/^((?P<mul>[0-9]+)n\+)(?P<pos>[0-9]+)$/is", $expression, $position)) {
                    if (isset($position['mul'])) {
                        return '(position() -'.$position['pos'].') mod '.$position['mul'].' = 0 and position() >= '.$position['pos'].'';
                    }
                }
            }
        }

        throw new RuntimeException('Invalid selector: unknown pseudo-class');
    }

    /**
     * @param  string $selector
     * @return array
     * @throws \RuntimeException if an empty string is passed or the selector is not valid
     */
    public static function getSegments($selector)
    {
        $selector = trim($selector);

        if ($selector === '') {
            throw new RuntimeException('Invalid selector');
        }

        $tag = '(?P<tag>[\*|\w|\-]+)?';
        $id = '(?:#(?P<id>[\w|\-]+))?';
        $classes = '(?P<classes>\.[\w|\-|\.]+)*';
        $attrs = '(?P<attrs>\[.+\])*';
        $child = '(?P<pseudo>[\w\-]*)';
        $expr = '(?:\((?P<expr>[^\)]+)\))';
        $pseudo = '(?::'.$child.$expr.'?)?';
        $rel = '\s*(?P<rel>>)?';

        $regexp = '/'.$tag.$id.$classes.$attrs.$pseudo.$rel.'/is';

        if (preg_match($regexp, $selector, $segments)) {
            $result['selector'] = $segments[0];
            $result['tag']      = (isset($segments['tag']) and $segments['tag'] !== '') ? $segments['tag'] : '*';

            // if the id attribute specified
            if (isset($segments['id']) and $segments['id'] !== '') {
                $result['id'] = $segments['id'];
            }

            // if the attributes specified
            if (isset($segments['attrs'])) {
                $attributes = trim($segments['attrs'], '[]');
                $attributes = explode('][', $attributes);

                foreach ($attributes as $attribute) {
                    if ($attribute !== '') {
                        list($name, $value) = array_pad(explode('=', $attribute), 2, null);

                        // if specified only the attribute name
                        $result['attributes'][$name] = $value;
                    }
                }
            }

            //if the class attribute specified
            if (isset($segments['classes'])) {
                $classes = trim($segments['classes'], '.');
                $classes = explode('.', $classes);

                foreach ($classes as $class) {
                    if ($class !== '') {
                        $result['classes'][] = $class;
                    }
                }
            }

            // if the pseudo class specified
            if (isset($segments['pseudo']) and $segments['pseudo'] !== '') {
                $result['pseudo'] = $segments['pseudo'];

                if (isset($segments['expr']) and $segments['expr'] !== '') {
                    $result['expr'] = $segments['expr'];
                }
            }

            // if it is a direct descendant
            if (isset($segments['rel'])) {
                $result['rel'] = $segments['rel'];
            }

            return $result;
        }

        throw new RuntimeException('Invalid selector');
    }

    /**
     * @return array
     */
    public static function getCompiled()
    {
        return static::$compiled;
    }

    /**
     * @param  array $compiled
     * @throws \InvalidArgumentException
     */
    public static function setCompiled($compiled)
    {
        if (!is_array($compiled)) {
            throw new InvalidArgumentException(sprintf('%s expects parameter 1 to be array, %s given', __METHOD__, gettype($compiled)));
        }

        static::$compiled = $compiled;
    }
}
