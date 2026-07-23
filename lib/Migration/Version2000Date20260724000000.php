<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Creates the table holding one conversation history per (room, user). */
class Version2000Date20260724000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('talkbot_sessions')) {
			$table = $schema->createTable('talkbot_sessions');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('token', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('history', Types::TEXT, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['token', 'user_id'], 'talkbot_sess_room_user');
			$table->addIndex(['updated_at'], 'talkbot_sess_updated');
		}

		return $schema;
	}
}
