<?php

namespace Kdgraphics\BaseTemplate\migrations;

use Craft;
use craft\db\Migration;
use putyourlightson\blitzhints\records\HintRecord;

/**
 * m250616_053047_install_blitzhints migration.
 */
class m250616_053047_install_blitzhints extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(HintRecord::tableName())) {
            $this->createTable(HintRecord::tableName(), [
                'id' => $this->primaryKey(),
                'fieldId' => $this->integer()->notNull(),
                'template' => $this->string()->notNull(),
                'routeVariable' => $this->string()->notNull(),
                'line' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Don't put the line number in the index to avoid duplicate hints
            // appearing when templates are edited and lines shifted around.
            $this->createIndex(null, HintRecord::tableName(), [
                'fieldId',
                'template',
                'routeVariable',
            ], true);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m250616_053047_install_blitzhints cannot be reverted.\n";
        return false;
    }
}
