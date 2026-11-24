<?php

namespace Tests\Unit\Services\Csv;

use App\Enums\Gender;
use App\Enums\JapaneseLevel;
use App\Enums\Nationality;
use App\Enums\ResidenceStatus;
use App\Services\Csv\CsvPropertyDefinition;
use App\Services\Csv\CsvPropertyRegistry;
use PHPUnit\Framework\TestCase;

class CsvPropertyRegistryTest extends TestCase
{
    private CsvPropertyRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new CsvPropertyRegistry();
    }

    public function testReturns26CanonicalKeys(): void
    {
        $all = $this->registry->all();
        $this->assertCount(26, $all);
    }

    public function testReturnsKeysInDocumentedOrder(): void
    {
        $expectedOrder = [
            'first_name',
            'last_name',
            'first_name_kana',
            'last_name_kana',
            'short_name',
            'birthday',
            'gender',
            'nationality',
            'phone',
            'emergency_contact_phone',
            'email',
            'postal_code',
            'prefecture',
            'address',
            'registered_location',
            'staff_no',
            'japanese_level',
            'residence_number',
            'residence_expiry_date',
            'residence_status',
            'passport_number',
            'passport_expiry_date',
            'student_card_expiry_date',
            'is_household_head',
            'facebook_link',
            'line_link',
        ];

        $actualKeys = array_keys($this->registry->all());
        $this->assertSame($expectedOrder, $actualKeys);
    }

    public function testDefaultOrderedKeysMatchesAllKeys(): void
    {
        $expected = array_keys($this->registry->all());
        $actual = $this->registry->defaultOrderedKeys();
        $this->assertSame($expected, $actual);
    }

    public function testAllDefinitionsAreCsvPropertyDefinitionInstances(): void
    {
        foreach ($this->registry->all() as $key => $definition) {
            $this->assertInstanceOf(
                CsvPropertyDefinition::class,
                $definition,
                "Key '{$key}' is not a CsvPropertyDefinition"
            );
        }
    }

    public function testAllKeysMatchDefinitionKey(): void
    {
        foreach ($this->registry->all() as $arrayKey => $definition) {
            $this->assertSame(
                $arrayKey,
                $definition->key,
                "Array key '{$arrayKey}' does not match definition key '{$definition->key}'"
            );
        }
    }

    public function testAllDefinitionsHaveValidSource(): void
    {
        $validSources = [CsvPropertyDefinition::SOURCE_USER, CsvPropertyDefinition::SOURCE_USER_INFO];
        foreach ($this->registry->all() as $key => $definition) {
            $this->assertContains(
                $definition->source,
                $validSources,
                "Key '{$key}' has invalid source '{$definition->source}'"
            );
        }
    }

    public function testAllDefinitionsHaveValidType(): void
    {
        $baseTypes = [
            CsvPropertyDefinition::TYPE_STRING,
            CsvPropertyDefinition::TYPE_DATE,
            CsvPropertyDefinition::TYPE_BOOLEAN,
        ];
        foreach ($this->registry->all() as $key => $definition) {
            $valid = in_array($definition->type, $baseTypes) || enum_exists($definition->type);
            $this->assertTrue($valid, "Key '{$key}' has invalid type '{$definition->type}'");
        }
    }

    public function testEnumTypesReferenceRealEnums(): void
    {
        $expectedEnums = [
            'gender' => Gender::class,
            'nationality' => Nationality::class,
            'japanese_level' => JapaneseLevel::class,
            'residence_status' => ResidenceStatus::class,
        ];
        foreach ($expectedEnums as $key => $enumClass) {
            $def = $this->registry->get($key);
            $this->assertNotNull($def, "Key '{$key}' not found in registry");
            $this->assertSame($enumClass, $def->type, "Key '{$key}' type mismatch");
            $this->assertTrue($def->isEnum(), "Key '{$key}' should be an enum");
        }
    }

    public function testStringTypedProperties(): void
    {
        $stringKeys = [
            'first_name',
            'last_name',
            'first_name_kana',
            'last_name_kana',
            'short_name',
            'phone',
            'emergency_contact_phone',
            'email',
            'postal_code',
            'prefecture',
            'address',
            'registered_location',
            'staff_no',
            'residence_number',
            'passport_number',
            'facebook_link',
            'line_link',
        ];
        foreach ($stringKeys as $key) {
            $def = $this->registry->get($key);
            $this->assertNotNull($def, "Key '{$key}' not found");
            $this->assertSame(CsvPropertyDefinition::TYPE_STRING, $def->type, "Key '{$key}' should be string");
            $this->assertFalse($def->isEnum(), "Key '{$key}' should not be enum");
        }
    }

    public function testDateTypedProperties(): void
    {
        $dateKeys = ['birthday', 'residence_expiry_date', 'passport_expiry_date', 'student_card_expiry_date'];
        foreach ($dateKeys as $key) {
            $def = $this->registry->get($key);
            $this->assertNotNull($def, "Key '{$key}' not found");
            $this->assertSame(CsvPropertyDefinition::TYPE_DATE, $def->type, "Key '{$key}' should be date");
        }
    }

    public function testBooleanTypedProperties(): void
    {
        $def = $this->registry->get('is_household_head');
        $this->assertNotNull($def);
        $this->assertSame(CsvPropertyDefinition::TYPE_BOOLEAN, $def->type);
    }

    public function testUserSourceFields(): void
    {
        $userSourceKeys = [
            'first_name',
            'last_name',
            'first_name_kana',
            'last_name_kana',
            'short_name',
            'email',
            'staff_no',
        ];
        foreach ($userSourceKeys as $key) {
            $def = $this->registry->get($key);
            $this->assertNotNull($def, "Key '{$key}' not found");
            $this->assertSame(CsvPropertyDefinition::SOURCE_USER, $def->source, "Key '{$key}' should be user source");
        }
    }

    public function testUserInfoSourceFields(): void
    {
        $userInfoKeys = [
            'birthday',
            'gender',
            'nationality',
            'phone',
            'emergency_contact_phone',
            'postal_code',
            'prefecture',
            'address',
            'registered_location',
            'japanese_level',
            'is_household_head',
            'facebook_link',
            'line_link',
            'residence_number',
            'residence_expiry_date',
            'residence_status',
            'passport_number',
            'passport_expiry_date',
            'student_card_expiry_date',
        ];
        foreach ($userInfoKeys as $key) {
            $def = $this->registry->get($key);
            $this->assertNotNull($def, "Key '{$key}' not found");
            $this->assertSame(
                CsvPropertyDefinition::SOURCE_USER_INFO,
                $def->source,
                "Key '{$key}' should be user_info source"
            );
        }
    }

    public function testValidateKeysAcceptsKnownKeys(): void
    {
        $allKeys = array_keys($this->registry->all());
        $unknown = $this->registry->validateKeys($allKeys);
        $this->assertEmpty($unknown);
    }

    public function testValidateKeysRejectsUnknownKeys(): void
    {
        $unknown = $this->registry->validateKeys(['not_a_field', 'also_fake']);
        $this->assertSame(['not_a_field', 'also_fake'], $unknown);
    }

    public function testValidateKeysReturnsOnlyUnknown(): void
    {
        $mixed = ['first_name', 'bogus_key', 'last_name', 'nope'];
        $unknown = $this->registry->validateKeys($mixed);
        $this->assertSame(['bogus_key', 'nope'], $unknown);
    }

    public function testValidateKeysAcceptsEmptyArray(): void
    {
        $unknown = $this->registry->validateKeys([]);
        $this->assertEmpty($unknown);
    }

    public function testGetReturnsDefinitionForKnownKey(): void
    {
        $def = $this->registry->get('email');
        $this->assertNotNull($def);
        $this->assertSame('email', $def->key);
    }

    public function testGetReturnsNullForUnknownKey(): void
    {
        $def = $this->registry->get('nonexistent');
        $this->assertNull($def);
    }

    public function testAllDefinitionsHaveJapaneseLabels(): void
    {
        foreach ($this->registry->all() as $key => $definition) {
            $this->assertNotEmpty($definition->labelJa, "Key '{$key}' has empty Japanese label");
        }
    }

    public function testAllDefinitionsHaveNonEmptyColumn(): void
    {
        foreach ($this->registry->all() as $key => $definition) {
            $this->assertNotEmpty($definition->column, "Key '{$key}' has empty column");
        }
    }
}
