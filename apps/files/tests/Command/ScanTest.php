<?php
/**
 * @author Sujith Haridasan <sharidasan@owncloud.com>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files\Tests\Command;

use OCA\Files\Command\Scan;
use Symfony\Component\Console\Tester\CommandTester;
use Test\TestCase;
use Test\Traits\UserTrait;
use OCP\IUserManager;
use OCP\IConfig;
use OCP\Files\IMimeTypeLoader;
use OCP\Lock\ILockingProvider;
use OCP\IUser;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\ILogger;

/**
 * Class ScanTest
 *
 * @group DB
 * @package OCA\Files\Tests\Command
 */
class ScanTest extends TestCase {
	use UserTrait;

	/**
	 * @var string
	 */
	private $dataDir;

	/**
	 * @var IDBConnection
	 */
	private $connection;

	/**
	 * @var IUserManager
	 */
	private $userManager;

	/**
	 * @var IGroupManager
	 */
	private $groupManager;

	/**
	 * @var ILockingProvider | \PHPUnit_Framework_MockObject_MockObject
	 */
	private $lockingProvider;

	/**
	 * @var IMimeTypeLoader | \PHPUnit_Framework_MockObject_MockObject
	 */
	private $mimeTypeLoader;

	/**
	 * @var IConfig | \PHPUnit_Framework_MockObject_MockObject
	 */
	private $config;

	/**
	 * @var IUser
	 */
	private $scanUser1;

	/**
	 * @var IUser
	 */
	private $scanUser2;

	/**
	 * @var CommandTester
	 */
	private $commandTester;

	/**
	 * @var string[]
	 */
	private $groupsCreated = [];

	protected function setUp() {
		if ($this->runsWithPrimaryObjectstorage()) {
			$this->markTestSkipped('not testing scanner as it does not make sense for primary object store');
		}
		parent::setUp();

		$this->connection = \OC::$server->getDatabaseConnection();
		$this->userManager = \OC::$server->getUserManager();
		$this->groupManager = \OC::$server->getGroupManager();
		$this->lockingProvider = $this->createMock(ILockingProvider::class);
		$this->mimeTypeLoader = $this->createMock(IMimeTypeLoader::class);
		$this->config = $this->createMock(IConfig::class);

		$command = new Scan(
			$this->userManager,
			$this->groupManager,
			$this->lockingProvider,
			$this->mimeTypeLoader,
			$this->createMock(ILogger::class),
			$this->config
		);
		$this->commandTester = new CommandTester($command);

		$this->scanUser1 = $this->createUser('scanuser1' . \uniqid());
		$this->scanUser2 = $this->createUser('scanuser2' . \uniqid());

		$user1 = $this->createUser('user1');
		$this->createUser('user2');
		$this->groupManager->createGroup('group1');
		$this->groupManager->get('group1')->addUser($user1);
		$this->groupsCreated[] = 'group1';

		$this->dataDir = \OC::$server->getConfig()->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data-autotest');

		@\mkdir($this->dataDir . '/' . $this->scanUser1->getUID() . '/files/toscan', 0777, true);
	}

	protected function tearDown() {
		foreach ($this->groupsCreated as $group) {
			$this->groupManager->get($group)->delete();
		}
		parent::tearDown();
	}

	public function dataInput() {
		return [
			[['--groups' => 'haystack'], 'Group name haystack doesn\'t exist'],
			[['--groups' => 'group1'], 'Starting scan for user 1 out of 1 (user1)'],
			[['--group' => ['haystack']], 'Group name haystack doesn\'t exist'],
			[['--group' => ['haystack,barn']], 'Group name haystack,barn doesn\'t exist'],
			[['--group' => ['group1']], 'Starting scan for user 1 out of 1 (user1)'],
			[['user_id' => ['user1']], 'Starting scan for user 1 out of 1 (user1)'],
			[['user_id' => ['user2']], 'Starting scan for user 1 out of 1 (user2)']
		];
	}

	/**
	 * @dataProvider dataInput
	 */
	public function testCommandInput($input, $expectedOutput) {
		$this->commandTester->execute($input);
		$output = $this->commandTester->getDisplay();
		$this->assertContains($expectedOutput, $output);
	}

	public function userInputData() {
		return [
			[['--group' => ['group1']], 'Starting scan for user 1 out of 200']
		];
	}

	/**
	 * @dataProvider userInputData
	 * @param $input
	 * @param $expectedOutput
	 */
	public function testGroupPaginationForUsers($input, $expectedOutput) {
		//First we populate the users
		$user = 'user';
		$numberOfUsersInGroup = 210;
		for ($i = 2; $i <= $numberOfUsersInGroup; $i++) {
			$userObj = $this->createUser($user.$i);
			$this->groupManager->get('group1')->addUser($userObj);
		}

		$this->commandTester->execute($input);
		$output = $this->commandTester->getDisplay();
		$this->assertContains($expectedOutput, $output);
		//If pagination works then below assert shouldn't fail
		$this->assertNotContains("Starting scan for user 1 out of $numberOfUsersInGroup", $output);
	}

	public function multipleGroupTest() {
		return [
			[['--groups' => 'group1,group2'], ''],
			[['--groups' => 'group1,group2,group3'], ''],
			[['--groups' => 'group1,group2', '--group' => ['group2','group3']], ''],
			[['--group' => ['group1,x','group2']], ''],
			[['--group' => ['group1','group2,x','group3']], '']
		];
	}

	/**
	 * @dataProvider multipleGroupTest
	 * @param $input
	 */
	public function testMultipleGroups($input) {
		//Create 10 users in each group
		$groups = [];
		if (\array_key_exists('--groups', $input)) {
			$groups = \explode(',', $input['--groups']);
		}
		if (\array_key_exists('--group', $input)) {
			$groups = \array_merge($groups, $input['--group']);
		}
		$user = "user";
		$userObj = [];
		for ($i = 1; $i <= (10 * \count($groups)); $i++) {
			$userObj[] = $this->createUser($user.$i);
		}

		$userCount = 1;
		foreach ($groups as $group) {
			if ($this->groupManager->groupExists($group) === false) {
				$this->groupManager->createGroup($group);
				$this->groupsCreated[] = $group;
				for ($i = $userCount; $i <= ($userCount + 9); $i++) {
					$j = $i - 1;
					$this->groupManager->get($group)->addUser($userObj[$j]);
				}
				$userCount = $i;
			} else {
				for ($i = $userCount; $i <= ($userCount + 9); $i++) {
					$j = $i - 1;
					$this->groupManager->get($group)->addUser($userObj[$j]);
				}
				$userCount = $i;
			}
		}

		$this->commandTester->execute($input);
		$output = $this->commandTester->getDisplay();
		if (\count($groups) === 2) {
			$this->assertContains('Starting scan for user 1 out of 10 (user1)', $output);
			$this->assertContains('Starting scan for user 1 out of 10 (user11)', $output);
		}
		if (\count($groups) === 3) {
			$this->assertContains('Starting scan for user 1 out of 10 (user1)', $output);
			$this->assertContains('Starting scan for user 1 out of 10 (user11)', $output);
			$this->assertContains('Starting scan for user 1 out of 10 (user21)', $output);
			$this->assertContains('Starting scan for user 10 out of 10 (user30)', $output);
		}
	}
}
