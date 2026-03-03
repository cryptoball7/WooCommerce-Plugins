<?php

use AgentCommerce\Core\Validation\Validator;

$schema = AGENT_COMMERCE_PATH . '/schemas/v1/test.schema.json';

echo $schema."\n";

echo "Validator Smoke Test\n";
echo "--------------------\n";

$result1 = Validator::validate([], $schema);

if(is_wp_error($result1))
 echo $result1->get_error_message()."\n";
else
 echo "Not an error.\n";

echo is_wp_error($result1)
    ? "PASS missing required detected\n"
    : "FAIL missing required not detected\n";

$result2 = Validator::validate(['name' => 'John'], $schema);
echo $result2 === true
    ? "PASS valid data accepted\n"
    : "FAIL valid data rejected\n";