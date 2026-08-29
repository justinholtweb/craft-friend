<?php

namespace justinholtweb\friend\db;

/**
 * Friend's database tables.
 */
abstract class Table
{
    public const RULES = '{{%friend_rules}}';
    public const PINS = '{{%friend_pins}}';
    public const LOG = '{{%friend_log}}';
}
