<?php

namespace Tests\Unit\ModelFilters;

use App\ModelFilters\AbstractFilter;
use App\Models\Role;
use App\Models\User;
use App\Models\UserFace;
use App\Models\UserInfo;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserFilterTest extends TestCase
{
    use RefreshDatabase;

    public function testInfoSearchFieldWithoutValueDoesNotFilter()
    {
        $matching = $this->createUserWithInfo(['passport_number' => 'PASS123']);
        $nonMatching = $this->createUserWithInfo(['passport_number' => 'OTHER999']);
        $withoutInfo = $this->createUserWithoutInfo();

        $users = User::filter(['info_search_field' => 'passport_number'])->get();

        $this->assertTrue($users->contains($matching));
        $this->assertTrue($users->contains($nonMatching));
        $this->assertTrue($users->contains($withoutInfo));
    }

    public function testInfoSearchValueWithoutFieldDoesNotFilter()
    {
        $matching = $this->createUserWithInfo(['passport_number' => 'PASS123']);
        $nonMatching = $this->createUserWithInfo(['passport_number' => 'OTHER999']);

        $users = User::filter(['info_search_value' => 'PASS'])->get();

        $this->assertTrue($users->contains($matching));
        $this->assertTrue($users->contains($nonMatching));
    }

    public function testInfoSearchValueWithResidenceNumber()
    {
        $user1 = User::factory()->create();
        UserInfo::factory()->create([
            'user_id' => $user1->id,
            'residence_number_1' => 'RES123',
        ]);

        $user2 = User::factory()->create();
        UserInfo::factory()->create([
            'user_id' => $user2->id,
            'residence_number_2' => 'RES456',
        ]);

        $user3 = User::factory()->create();
        UserInfo::factory()->create([
            'user_id' => $user3->id,
            'residence_number_3' => 'RES789',
        ]);

        $user4 = User::factory()->create();
        UserInfo::factory()->create([
            'user_id' => $user4->id,
            'residence_number_1' => 'OTHER',
        ]);

        $query = User::filter([
            'info_search_field' => 'residence_number',
            'info_search_value' => 'RES',
        ]);
        $users = $query->get();

        $this->assertCount(3, $users);
        $this->assertTrue($users->contains($user1));
        $this->assertTrue($users->contains($user2));
        $this->assertTrue($users->contains($user3));
        $this->assertFalse($users->contains($user4));
    }

    public function testInfoSearchValueWithOtherFields()
    {
        $user1 = User::factory()->create();
        UserInfo::factory()->create([
            'user_id' => $user1->id,
            'passport_number' => 'PASS123',
        ]);

        $user2 = User::factory()->create();
        UserInfo::factory()->create([
            'user_id' => $user2->id,
            'passport_number' => 'PASS456',
        ]);

        $user3 = User::factory()->create();
        UserInfo::factory()->create([
            'user_id' => $user3->id,
            'passport_number' => 'OTHER',
        ]);

        $query = User::filter([
            'info_search_field' => 'passport_number',
            'info_search_value' => 'PASS',
        ]);
        $users = $query->get();

        $this->assertCount(2, $users);
        $this->assertTrue($users->contains($user1));
        $this->assertTrue($users->contains($user2));
        $this->assertFalse($users->contains($user3));
    }

    public function testInfoSearchValueTreatsLikeWildcardsAsLiterals()
    {
        $literal = $this->createUserWithInfo(['passport_number' => 'A%B']);
        $other = $this->createUserWithInfo(['passport_number' => 'XYZ']);

        $users = User::filter([
            'info_search_field' => 'passport_number',
            'info_search_value' => '%',
        ])->get();

        $this->assertTrue($users->contains($literal));
        $this->assertFalse($users->contains($other));
    }

    public function testInfoSearchValueTreatsUnderscoreAsLiteral()
    {
        $literal = $this->createUserWithInfo(['passport_number' => 'A_B']);
        $other = $this->createUserWithInfo(['passport_number' => 'AXB']);

        $users = User::filter([
            'info_search_field' => 'passport_number',
            'info_search_value' => 'A_B',
        ])->get();

        $this->assertTrue($users->contains($literal));
        $this->assertFalse($users->contains($other));
    }

    private function createUserWithoutInfo(): User
    {
        $user = User::factory()->create();
        UserInfo::query()->where('user_id', $user->id)->delete();

        return $user;
    }

    private function createUserWithInfo(array $attributes): User
    {
        $user = User::factory()->create();
        UserInfo::query()->where('user_id', $user->id)->delete();

        $info = new UserInfo();
        $info->user_id = $user->id;
        foreach ($attributes as $key => $value) {
            $info->{$key} = $value;
        }
        $info->save();

        return $user;
    }

    public function testInfoSearchValueMatchesAddressWithVietnameseDStroke()
    {
        // Stored as "Dong Da": the `đ` is stripped on write, and utf8mb4_unicode_ci
        // treats `đ` as a distinct letter, so the raw needle can never match.
        $user = $this->createUserWithInfo(['address' => 'Đống Đa']);

        $users = User::filter([
            'info_search_field' => 'address',
            'info_search_value' => 'Đống Đa',
        ])->get();

        $this->assertTrue($users->contains($user));
    }

    public function testInfoSearchValueMatchesAddressWithComma()
    {
        // Stored as "Ha Noi Cau Giay": commas are replaced with a space on write.
        $user = $this->createUserWithInfo(['address' => 'Hà Nội, Cầu Giấy']);

        $users = User::filter([
            'info_search_field' => 'address',
            'info_search_value' => 'Nội, Cầu',
        ])->get();

        $this->assertTrue($users->contains($user));
    }

    public function testInfoSearchValueStillExcludesNonMatchingAddress()
    {
        $match = $this->createUserWithInfo(['address' => 'Đống Đa']);
        $other = $this->createUserWithInfo(['address' => 'Cầu Giấy']);

        $users = User::filter([
            'info_search_field' => 'address',
            'info_search_value' => 'Đống Đa',
        ])->get();

        $this->assertTrue($users->contains($match));
        $this->assertFalse($users->contains($other));
    }

    public function testInfoSearchValueMatchesPrefectureWithSurroundingWhitespace()
    {
        $user = $this->createUserWithInfo(['prefecture' => 'Tokyo']);

        $users = User::filter([
            'info_search_field' => 'prefecture',
            'info_search_value' => '  Tokyo  ',
        ])->get();

        $this->assertTrue($users->contains($user));
    }

    public function testInfoSearchValueIgnoredWhenNormalizationEmptiesTheNeedle()
    {
        $user = $this->createUserWithInfo(['prefecture' => 'tokyo']);

        $users = User::filter([
            'info_search_field' => 'prefecture',
            'info_search_value' => '   ',
        ])->get();

        $this->assertTrue($users->contains($user));
    }

    public function testInfoSearchValueDoesNotNormalizeNonNormalizedColumns()
    {
        $user = $this->createUserWithInfo(['registered_location' => 'Hà Nội']);

        $users = User::filter([
            'info_search_field' => 'registered_location',
            'info_search_value' => 'Hà Nội',
        ])->get();

        $this->assertTrue($users->contains($user));
    }

    public function testInfoSearchValueIgnoresFieldOutsideAllowList()
    {
        $match = User::factory()->create();
        UserInfo::factory()->create([
            'user_id' => $match->id,
            'nationality' => 'Vietnam',
        ]);

        $other = User::factory()->create();
        UserInfo::factory()->create([
            'user_id' => $other->id,
            'nationality' => 'Japan',
        ]);

        // `nationality` is a real user_infos column but is not exposed for search,
        // so the filter must be ignored rather than applied.
        $query = User::filter([
            'info_search_field' => 'nationality',
            'info_search_value' => 'Vietnam',
        ]);
        $users = $query->get();

        $this->assertTrue($users->contains($match));
        $this->assertTrue($users->contains($other));
    }

    public function testInfoSearchValueIgnoresUnknownColumnInsteadOfFailing()
    {
        $user = User::factory()->create();

        $query = User::filter([
            'info_search_field' => 'no_such_column',
            'info_search_value' => 'anything',
        ]);
        $users = $query->get();

        $this->assertTrue($users->contains($user));
    }

    public function testId()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $query = User::filter(['id' => $user1->id]);
        $users = $query->get();

        $this->assertTrue($users->contains($user1));
        $this->assertFalse($users->contains($user2));
    }

    public function testIds()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        $query = User::filter(['ids' => [$user1->id, $user2->id]]);
        $users = $query->get();

        $this->assertTrue($users->contains($user1));
        $this->assertTrue($users->contains($user2));
        $this->assertFalse($users->contains($user3));
    }

    public function testStaffNoEmpty()
    {
        $user1 = User::factory()->create(['staff_no' => null]);
        $user2 = User::factory()->create(['staff_no' => '123']);

        $query = User::filter(['staff_no_empty' => true]);
        $users = $query->get();

        $this->assertTrue($users->contains($user1));
        $this->assertFalse($users->contains($user2));
    }

    public function testWorkplace()
    {
        $user1 = User::factory()->create();
        $workplace = Workplace::factory()->create();
        $user1->workplaces()->attach($workplace->id);

        $user2 = User::factory()->create();

        $users = User::filter(['workplace' => $workplace->id])->get();

        $this->assertTrue($users->contains($user1));
        $this->assertFalse($users->contains($user2));
    }

    public function testWorkplaceAllValueDoesNotFilter()
    {
        $userWithWorkplace = User::factory()->create();
        $workplace = Workplace::factory()->create();
        $userWithWorkplace->workplaces()->attach($workplace->id);

        $userWithoutWorkplace = User::factory()->create();

        $users = User::filter(['workplace' => AbstractFilter::ALL_VALUE_INT])->get();

        $this->assertTrue($users->contains($userWithWorkplace));
        $this->assertTrue($users->contains($userWithoutWorkplace));
    }

    public function testWorkplaceNone()
    {
        $user1 = User::factory()->create();
        $workplace = Workplace::factory()->create();
        $user1->workplaces()->attach($workplace->id);

        $user2 = User::factory()->create();

        $query = User::filter(['workplace_none' => true]);
        $users = $query->get();

        $this->assertFalse($users->contains($user1));
        $this->assertTrue($users->contains($user2));
    }

    public function testIsDisabled()
    {
        $user1 = User::factory()->create(['is_disabled' => 1]);
        $user2 = User::factory()->create(['is_disabled' => 0]);

        $queryAll = User::filter(['is_disabled' => 'all']);
        $this->assertNotNull($queryAll);

        $query1 = User::filter(['is_disabled' => '1']);
        $users1 = $query1->get();
        $this->assertTrue($users1->contains($user1));
        $this->assertFalse($users1->contains($user2));
    }

    public function testPaperStatus()
    {
        $user1 = User::factory()->create(['paper_status' => 'verified']);
        $user2 = User::factory()->create(['paper_status' => 'pending']);

        $queryAll = User::filter(['paper_status' => 'all']);
        $this->assertNotNull($queryAll);

        $query1 = User::filter(['paper_status' => 'verified']);
        $users1 = $query1->get();
        $this->assertTrue($users1->contains($user1));
        $this->assertFalse($users1->contains($user2));
    }

    public function testRole()
    {
        $user1 = User::factory()->create();
        $role = Role::create(['name' => 'test_role']);
        $user1->addRole($role);

        $user2 = User::factory()->create();

        $queryAll = User::filter(['role' => 'all']);
        $this->assertNotNull($queryAll);

        $query1 = User::filter(['role' => 'test_role']);
        $users1 = $query1->get();
        $this->assertTrue($users1->contains($user1));
        $this->assertFalse($users1->contains($user2));
    }

    public function testFaceRegistered()
    {
        $provider = config('services.face_recognition.provider');

        $user1 = User::factory()->create();
        UserFace::factory()->create([
            'user_id' => $user1->id,
            'provider' => $provider,
        ]);

        $user2 = User::factory()->create();

        $queryAll = User::filter(['face_registered' => 'all']);
        $this->assertNotNull($queryAll);

        $query1 = User::filter(['face_registered' => '1']);
        $users1 = $query1->get();
        $this->assertTrue($users1->contains($user1));
        $this->assertFalse($users1->contains($user2));

        $query0 = User::filter(['face_registered' => '0']);
        $users0 = $query0->get();
        $this->assertFalse($users0->contains($user1));
        $this->assertTrue($users0->contains($user2));
    }
}
