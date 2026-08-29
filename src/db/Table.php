<?php

namespace justinholtweb\friends\db;

/**
 * Friends' database tables.
 */
abstract class Table
{
    public const RULES = '{{%friends_rules}}';
    public const PINS = '{{%friends_pins}}';
    public const LOG = '{{%friends_log}}';
}
