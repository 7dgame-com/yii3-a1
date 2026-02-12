<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\Code;
use App\Model\Manager;
use App\Model\MetaCode;
use App\Model\Phototype;
use App\Model\UserLinked;
use App\Model\VerseCode;
use App\Model\Watermark;
use App\Model\Group;
use App\Model\GroupUser;
use App\Model\GroupVerse;
use App\Model\Property;
use App\Model\Resource;
use App\Model\Tags;
use App\Model\VerseTags;
use App\Model\VerseProperty;
use PHPUnit\Framework\TestCase;
use Yiisoft\ActiveRecord\ActiveRecord;

/**
 * Unit tests for associated/auxiliary ActiveRecord models.
 *
 * Validates Requirements 8.8:
 * - All models extend ActiveRecord
 * - All models return correct table names
 */
final class AssociatedModelsTest extends TestCase
{
    /**
     * @dataProvider modelTableNameProvider
     */
    public function testModelExtendsActiveRecord(string $className): void
    {
        $model = new $className();
        $this->assertInstanceOf(ActiveRecord::class, $model);
    }

    /**
     * @dataProvider modelTableNameProvider
     */
    public function testModelReturnsCorrectTableName(string $className, string $expectedTable): void
    {
        $model = new $className();
        $this->assertSame($expectedTable, $model->getTableName());
    }

    /**
     * Data provider: model class => expected table name.
     */
    public static function modelTableNameProvider(): array
    {
        return [
            'Manager' => [Manager::class, 'manager'],
            'Code' => [Code::class, 'code'],
            'VerseCode' => [VerseCode::class, 'verse_code'],
            'MetaCode' => [MetaCode::class, 'meta_code'],
            'UserLinked' => [UserLinked::class, 'user_linked'],
            'Watermark' => [Watermark::class, 'watermark'],
            'Phototype' => [Phototype::class, 'phototype'],
            'Group' => [Group::class, 'group'],
            'GroupUser' => [GroupUser::class, 'group_user'],
            'GroupVerse' => [GroupVerse::class, 'group_verse'],
            'Property' => [Property::class, 'property'],
            'Resource' => [Resource::class, 'resource'],
            'Tags' => [Tags::class, 'tags'],
            'VerseTags' => [VerseTags::class, 'verse_tags'],
            'VerseProperty' => [VerseProperty::class, 'verse_property'],
        ];
    }
}
