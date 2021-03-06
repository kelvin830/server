<?php
/**
 * @copyright Copyright (c) 2018, Georg Ehrke
 *
 * @author Georg Ehrke <oc.list@georgehrke.com>
 *
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

namespace OCA\DAV\Tests\unit\BackgroundJob;

use OCA\DAV\BackgroundJob\UpdateCalendarResourcesRoomsBackgroundJob;

use OCA\DAV\CalDAV\CalDavBackend;
use OCP\Calendar\BackendTemporarilyUnavailableException;
use OCP\Calendar\IMetadataProvider;
use OCP\Calendar\Resource\IBackend;
use OCP\Calendar\Resource\IManager as IResourceManager;
use OCP\Calendar\Resource\IResource;
use OCP\Calendar\Room\IManager as IRoomManager;
use Test\TestCase;

class UpdateCalendarResourcesRoomsBackgroundJobTest extends TestCase {

	/** @var UpdateCalendarResourcesRoomsBackgroundJob */
	private $backgroundJob;

	/** @var IResourceManager | \PHPUnit_Framework_MockObject_MockObject */
	private $resourceManager;

	/** @var IRoomManager | \PHPUnit_Framework_MockObject_MockObject */
	private $roomManager;

	/** @var CalDavBackend | \PHPUnit_Framework_MockObject_MockObject */
	private $calDavBackend;

	protected function setUp() {
		parent::setUp();

		$this->resourceManager = $this->createMock(IResourceManager::class);
		$this->roomManager = $this->createMock(IRoomManager::class);
		$this->calDavBackend = $this->createMock(CalDavBackend::class);

		$this->backgroundJob = new UpdateCalendarResourcesRoomsBackgroundJob(
			$this->resourceManager, $this->roomManager, self::$realDatabase,
			$this->calDavBackend);
	}

	protected function tearDown() {
		$query = self::$realDatabase->getQueryBuilder();
		$query->delete('calendar_resources')->execute();
		$query->delete('calendar_resources_md')->execute();
		$query->delete('calendar_rooms')->execute();
		$query->delete('calendar_rooms_md')->execute();
	}

	/**
	 * Data in Cache:
	 * resources:
	 *  [backend1, res1, Beamer1, {}] - []
	 *  [backend1, res2, TV1, {}] - []
	 *  [backend2, res3, Beamer2, {}] - ['meta1' => 'value1', 'meta2' => 'value2']
	 *  [backend2, res4, TV2, {}] - ['meta1' => 'value1', 'meta3' => 'value3-old']
	 *  [backend3, res5, Beamer3, {}] - []
	 *  [backend3, res6, Pointer, {foo, bar}] - ['meta99' => 'value99']
	 *
	 * Data in Backend:
	 *  backend1 gone
	 *  backend2 throws BackendTemporarilyUnavailableException
	 *  [backend3, res6, Pointer123, {foo, biz}] - ['meta99' => 'value99-new', 'meta123' => 'meta456']
	 *  [backend3, res7, Resource4, {biz}] - ['meta1' => 'value1']
	 *  [backend4, res8, Beamer, {}] - ['meta2' => 'value2']
	 *  [backend4, res9, Beamer2, {}] - []
	 *
	 * Expected after run:
	 * 	[backend1, res1, Beamer1, {}] - []
	 *  [backend1, res2, TV1, {}] - []
	 *  [backend2, res3, Beamer2, {}] - ['meta1' => 'value1', 'meta2' => 'value2']
	 *  [backend2, res4, TV2, {}] - ['meta1' => 'value1', 'meta3' => 'value3-old']
	 *  [backend3, res6, Pointer123, {foo, biz}]  - ['meta99' => 'value99-new', 'meta123' => 'meta456']
	 *  [backend3, res7, Resource4, {biz}] - ['meta1' => 'value1']
	 *  [backend4, res8, Beamer, {}] - ['meta2' => 'value2']
	 *  [backend4, res9, Beamer2, {}] - []
	 */

	public function testRun() {
		$this->createTestResourcesInCache();

		$backend2 = $this->createMock(IBackend::class);
		$backend3 = $this->createMock(IBackend::class);
		$backend4 = $this->createMock(IBackend::class);

		$res6 = $this->createMock([IResource::class, IMetadataProvider::class]);
		$res7 = $this->createMock([IResource::class, IMetadataProvider::class]);
		$res8 = $this->createMock([IResource::class, IMetadataProvider::class]);
		$res9 = $this->createMock(IResource::class);

		$backend2->method('getBackendIdentifier')
			->will($this->returnValue('backend2'));
		$backend2->method('listAllResources')
			->will($this->throwException(new BackendTemporarilyUnavailableException()));
		$backend2->method('getResource')
			->will($this->throwException(new BackendTemporarilyUnavailableException()));
		$backend2->method('getAllResources')
			->will($this->throwException(new BackendTemporarilyUnavailableException()));
		$backend3->method('getBackendIdentifier')
			->will($this->returnValue('backend3'));
		$backend3->method('listAllResources')
			->will($this->returnValue(['res6', 'res7']));
		$backend3->method('getResource')
			->will($this->returnValueMap([
				['res6', $res6],
				['res7', $res7],
			]));
		$backend4->method('getBackendIdentifier')
			->will($this->returnValue('backend4'));
		$backend4->method('listAllResources')
			->will($this->returnValue(['res8', 'res9']));
		$backend4->method('getResource')
			->will($this->returnValueMap([
				['res8', $res8],
				['res9', $res9],
			]));

		$res6->method('getId')->will($this->returnValue('res6'));
		$res6->method('getDisplayName')->will($this->returnValue('Pointer123'));
		$res6->method('getGroupRestrictions')->will($this->returnValue(['foo', 'biz']));
		$res6->method('getEMail')->will($this->returnValue('res6@foo.bar'));
		$res6->method('getBackend')->will($this->returnValue($backend3));

		$res6->method('getAllAvailableMetadataKeys')->will($this->returnValue(['meta99', 'meta123']));
		$res6->method('getMetadataForKey')->will($this->returnCallback(function($key) {
			switch($key) {
				case 'meta99':
					return 'value99-new';

				case 'meta123':
					return 'meta456';

				default:
					return null;
			}
		}));

		$res7->method('getId')->will($this->returnValue('res7'));
		$res7->method('getDisplayName')->will($this->returnValue('Resource4'));
		$res7->method('getGroupRestrictions')->will($this->returnValue(['biz']));
		$res7->method('getEMail')->will($this->returnValue('res7@foo.bar'));
		$res7->method('getBackend')->will($this->returnValue($backend3));
		$res7->method('getAllAvailableMetadataKeys')->will($this->returnValue(['meta1']));
		$res7->method('getMetadataForKey')->will($this->returnCallback(function($key) {
			switch($key) {
				case 'meta1':
					return 'value1';

				default:
					return null;
			}
		}));

		$res8->method('getId')->will($this->returnValue('res8'));
		$res8->method('getDisplayName')->will($this->returnValue('Beamer'));
		$res8->method('getGroupRestrictions')->will($this->returnValue([]));
		$res8->method('getEMail')->will($this->returnValue('res8@foo.bar'));
		$res8->method('getBackend')->will($this->returnValue($backend4));
		$res8->method('getAllAvailableMetadataKeys')->will($this->returnValue(['meta2']));
		$res8->method('getMetadataForKey')->will($this->returnCallback(function($key) {
			switch($key) {
				case 'meta2':
					return 'value2';

				default:
					return null;
			}
		}));

		$res9->method('getId')->will($this->returnValue('res9'));
		$res9->method('getDisplayName')->will($this->returnValue('Beamer2'));
		$res9->method('getGroupRestrictions')->will($this->returnValue([]));
		$res9->method('getEMail')->will($this->returnValue('res9@foo.bar'));
		$res9->method('getBackend')->will($this->returnValue($backend4));

		$this->resourceManager
			->method('getBackends')
			->will($this->returnValue([
				$backend2, $backend3, $backend4
			]));
		$this->resourceManager
			->method('getBackend')
			->will($this->returnValueMap([
				['backend2', $backend2],
				['backend3', $backend3],
				['backend4', $backend4],
			]));

		$this->backgroundJob->run([]);

		$query = self::$realDatabase->getQueryBuilder();
		$query->select('*')->from('calendar_resources');

		$rows = [];
		$ids = [];
		$stmt = $query->execute();
		while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$ids[$row['backend_id'] . '::' . $row['resource_id']] = $row['id'];
			unset($row['id']);
			$rows[] = $row;
		}

		$this->assertEquals([
			[
				'backend_id' => 'backend1',
				'resource_id' => 'res1',
				'displayname' => 'Beamer1',
				'email' => 'res1@foo.bar',
				'group_restrictions' => '[]',
			],
			[
				'backend_id' => 'backend1',
				'resource_id' => 'res2',
				'displayname' => 'TV1',
				'email' => 'res2@foo.bar',
				'group_restrictions' => '[]',
			],
			[
				'backend_id' => 'backend2',
				'resource_id' => 'res3',
				'displayname' => 'Beamer2',
				'email' => 'res3@foo.bar',
				'group_restrictions' => '[]',
			],
			[
				'backend_id' => 'backend2',
				'resource_id' => 'res4',
				'displayname' => 'TV2',
				'email' => 'res4@foo.bar',
				'group_restrictions' => '[]',
			],
			[
				'backend_id' => 'backend3',
				'resource_id' => 'res6',
				'displayname' => 'Pointer123',
				'email' => 'res6@foo.bar',
				'group_restrictions' => '["foo","biz"]',
			],
			[
				'backend_id' => 'backend3',
				'resource_id' => 'res7',
				'displayname' => 'Resource4',
				'email' => 'res7@foo.bar',
				'group_restrictions' => '["biz"]',
			],
			[
				'backend_id' => 'backend4',
				'resource_id' => 'res8',
				'displayname' => 'Beamer',
				'email' => 'res8@foo.bar',
				'group_restrictions' => '[]',
			],
			[
				'backend_id' => 'backend4',
				'resource_id' => 'res9',
				'displayname' => 'Beamer2',
				'email' => 'res9@foo.bar',
				'group_restrictions' => '[]',
			],
		], $rows);

		$query2 = self::$realDatabase->getQueryBuilder();
		$query2->select('*')->from('calendar_resources_md');

		$rows2 = [];
		$stmt = $query2->execute();
		while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			unset($row['id']);
			$rows2[] = $row;
		}

		$this->assertEquals([
			[
				'resource_id' => $ids['backend2::res3'],
				'key' => 'meta1',
				'value' => 'value1',
			],
			[
				'resource_id' => $ids['backend2::res3'],
				'key' => 'meta2',
				'value' => 'value2',
			],
			[
				'resource_id' => $ids['backend2::res4'],
				'key' => 'meta1',
				'value' => 'value1',
			],
			[
				'resource_id' => $ids['backend2::res4'],
				'key' => 'meta3',
				'value' => 'value3-old',
			],
			[
				'resource_id' => $ids['backend3::res6'],
				'key' => 'meta99',
				'value' => 'value99-new',
			],
			[
				'resource_id' => $ids['backend3::res7'],
				'key' => 'meta1',
				'value' => 'value1',
			],
			[
				'resource_id' => $ids['backend3::res6'],
				'key' => 'meta123',
				'value' => 'meta456',
			],
			[
				'resource_id' => $ids['backend4::res8'],
				'key' => 'meta2',
				'value' => 'value2',
			]
		], $rows2);
	}

	protected function createTestResourcesInCache() {
		$query = self::$realDatabase->getQueryBuilder();
		$query->insert('calendar_resources')
			->values([
				'backend_id' => $query->createNamedParameter('backend1'),
				'resource_id' => $query->createNamedParameter('res1'),
				'email' => $query->createNamedParameter('res1@foo.bar'),
				'displayname' => $query->createNamedParameter('Beamer1'),
				'group_restrictions' => $query->createNamedParameter('[]'),
			])
			->execute();

		$query->insert('calendar_resources')
			->values([
				'backend_id' => $query->createNamedParameter('backend1'),
				'resource_id' => $query->createNamedParameter('res2'),
				'email' => $query->createNamedParameter('res2@foo.bar'),
				'displayname' => $query->createNamedParameter('TV1'),
				'group_restrictions' => $query->createNamedParameter('[]'),
			])
			->execute();

		$query->insert('calendar_resources')
			->values([
				'backend_id' => $query->createNamedParameter('backend2'),
				'resource_id' => $query->createNamedParameter('res3'),
				'email' => $query->createNamedParameter('res3@foo.bar'),
				'displayname' => $query->createNamedParameter('Beamer2'),
				'group_restrictions' => $query->createNamedParameter('[]'),
			])
			->execute();
		$id3 = $query->getLastInsertId();

		$query->insert('calendar_resources')
			->values([
				'backend_id' => $query->createNamedParameter('backend2'),
				'resource_id' => $query->createNamedParameter('res4'),
				'email' => $query->createNamedParameter('res4@foo.bar'),
				'displayname' => $query->createNamedParameter('TV2'),
				'group_restrictions' => $query->createNamedParameter('[]'),
			])
			->execute();
		$id4 = $query->getLastInsertId();

		$query->insert('calendar_resources')
			->values([
				'backend_id' => $query->createNamedParameter('backend3'),
				'resource_id' => $query->createNamedParameter('res5'),
				'email' => $query->createNamedParameter('res5@foo.bar'),
				'displayname' => $query->createNamedParameter('Beamer3'),
				'group_restrictions' => $query->createNamedParameter('[]'),
			])
			->execute();

		$query->insert('calendar_resources')
			->values([
				'backend_id' => $query->createNamedParameter('backend3'),
				'resource_id' => $query->createNamedParameter('res6'),
				'email' => $query->createNamedParameter('res6@foo.bar'),
				'displayname' => $query->createNamedParameter('Pointer'),
				'group_restrictions' => $query->createNamedParameter('["foo", "bar"]'),
			])
			->execute();
		$id6 = $query->getLastInsertId();

		$query->insert('calendar_resources_md')
			->values([
				'resource_id' => $query->createNamedParameter($id3),
				'key' => $query->createNamedParameter('meta1'),
				'value' => $query->createNamedParameter('value1')
			])
			->execute();
		$query->insert('calendar_resources_md')
			->values([
				'resource_id' => $query->createNamedParameter($id3),
				'key' => $query->createNamedParameter('meta2'),
				'value' => $query->createNamedParameter('value2')
			])
			->execute();
		$query->insert('calendar_resources_md')
			->values([
				'resource_id' => $query->createNamedParameter($id4),
				'key' => $query->createNamedParameter('meta1'),
				'value' => $query->createNamedParameter('value1')
			])
			->execute();
		$query->insert('calendar_resources_md')
			->values([
				'resource_id' => $query->createNamedParameter($id4),
				'key' => $query->createNamedParameter('meta3'),
				'value' => $query->createNamedParameter('value3-old')
			])
			->execute();
		$query->insert('calendar_resources_md')
			->values([
				'resource_id' => $query->createNamedParameter($id6),
				'key' => $query->createNamedParameter('meta99'),
				'value' => $query->createNamedParameter('value99')
			])
			->execute();
	}
}
