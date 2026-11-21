<?php
/**
 * Throwaway (MD3-35): fails on purpose to check that the branch rules block
 * the merge. Never merged.
 */
class Test_Ruleset_Check extends \PHPUnit\Framework\TestCase {
	public function test_fails_on_purpose() {
		$this->fail( 'Deliberate failure to check the branch rules (MD3-35).' );
	}
}
