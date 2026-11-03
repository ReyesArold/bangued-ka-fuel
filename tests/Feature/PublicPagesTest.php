<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Station;
use App\Models\User;
use Tests\TestCase;

/**
 * The public About and Privacy Policy pages.
 *
 * These pages are the ones a reader reaches when something else has gone wrong,
 * so they are pinned on two things specifically:
 *
 *   1. They render at all, with no database and no session. A policy page that
 *      500s when the station data is broken is no use to anybody.
 *   2. The claims in the copy stay true. A privacy policy asserting "we collect
 *      nothing" while the API publishes administrator email addresses would be
 *      worse than having no policy, so the manager-identity exclusion is
 *      asserted against the live payload rather than trusted to a comment.
 */
class PublicPagesTest extends TestCase
{
    public function test_about_and_privacy_are_routable_and_named(): void
    {
        $this->get('/about')->assertOk();
        $this->get('/privacy')->assertOk();

        $this->assertSame(url('/about'), route('about'));
        $this->assertSame(url('/privacy'), route('privacy'));
    }

    /**
     * Both pages must render with the tables dropped and nobody signed in. No
     * RefreshDatabase here on purpose: TestCase::setUp migrates an in-memory
     * database and this test simply never touches it, which is the closest
     * approximation of "the database is unavailable" available without
     * breaking the connection out from under the framework.
     */
    public function test_pages_render_without_reading_the_database(): void
    {
        foreach (['/about', '/privacy'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('page-title', false)
                ->assertSee('Skip to content', false);
        }
    }

    /** Public content, so both pages must be indexable and carry a description. */
    public function test_pages_are_indexable_and_described(): void
    {
        foreach (['about' => 'About', 'privacy' => 'Privacy Policy'] as $name => $expected) {
            $response = $this->get(route($name));

            // login.html sets noindex; these two must not.
            $response->assertDontSee('noindex', false);

            $response->assertSee('<title>'.$expected.' | Bangued-Ka-Fuel</title>', false);
            $response->assertSee('name="description"', false);
        }
    }

    /** The map's site footer has to reach both pages. */
    public function test_pages_are_linked_from_the_public_map_footer(): void
    {
        // index.html is served as a file, so it cannot call route() and the two
        // paths are literal. This is the assertion that keeps them in step with
        // routes/web.php.
        $html = file_get_contents(public_path('index.html'));

        $this->assertStringContainsString('href="/about"', $html);
        $this->assertStringContainsString('href="/privacy"', $html);
        $this->assertStringContainsString('class="mapfoot"', $html);

        // Regression guard. The links used to live inside the collapsible
        // legend body, whose collapsed state is persisted in localStorage - so
        // they were completely invisible to anyone who had ever folded the
        // panel. Assert they sit outside it, and outside the legend entirely.
        $this->assertStringNotContainsString('class="legend-foot"', $html);

        $legendEnd = strpos($html, '</section>');
        $footerAt = strpos($html, 'class="mapfoot"');

        $this->assertNotFalse($legendEnd, 'The legend section should close.');
        $this->assertNotFalse($footerAt, 'The map footer should be present.');
        $this->assertGreaterThan(
            $legendEnd,
            $footerAt,
            'The map footer must sit after the legend, not inside its collapsible body.'
        );
    }

    /** The admin map is the other surface that renders the footer. */
    public function test_pages_are_linked_from_the_admin_map_footer(): void
    {
        $html = file_get_contents(resource_path('views/admin/map.blade.php'));

        $this->assertStringContainsString("route('about')", $html);
        $this->assertStringContainsString("route('privacy')", $html);
        $this->assertStringContainsString('class="mapfoot"', $html);
        $this->assertStringNotContainsString('class="legend-foot"', $html);
    }

    /**
     * Both pages must offer a way to report a wrong price or location, and it
     * must come from config rather than being typed into each view - two
     * hard-coded addresses would eventually disagree.
     */
    public function test_pages_publish_the_configured_contact_address(): void
    {
        config(['bangued.contact.email' => 'fixme@bangued.example']);

        foreach (['/about', '/privacy'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('mailto:fixme@bangued.example', false)
                ->assertSee('fixme@bangued.example');
        }
    }

    /** The nav marks the open tab, and only the open tab. */
    public function test_navigation_marks_the_current_page(): void
    {
        $this->get('/about')
            ->assertSee('<a href="'.route('about').'" aria-current="page">About</a>', false);

        $this->get('/privacy')
            ->assertSee('<a href="'.route('privacy').'" aria-current="page">Privacy</a>', false);

        // Cross-linked, and neither cross-link claims to be current.
        $this->get('/about')->assertSee('href="'.route('privacy').'"', false);
    }

    public function test_every_page_offers_a_way_back_to_the_map(): void
    {
        foreach (['/about', '/privacy'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('Back to the map', false)
                ->assertSee('href="'.route('home').'"', false);
        }
    }

    /**
     * The claim the privacy policy actually rests on.
     *
     * The policy says no personal information is published for a visitor who
     * only looks at the map. That is only true while the public station payload
     * omits the nested `manager` object: for an account created from the
     * "+ New manager" dialog, users.username IS the manager's email address.
     *
     * Seeded first so a manager is genuinely assigned to a published station -
     * without one the assertions below would pass vacuously.
     */
    public function test_public_station_payload_does_not_leak_manager_identity(): void
    {
        [$station, $manager] = $this->assignAManagerToAPublishedStation();

        foreach (['/api/stations', '/api/stations/'.$station->id] as $url) {
            $body = $this->getJson($url)
                ->assertOk()
                ->getContent();

            $this->assertStringNotContainsString($manager->email, $body, $url);
            $this->assertStringNotContainsString($manager->display_name, $body, $url);
        }

        $payload = $this->getJson('/api/stations')->json('data.0');

        $this->assertArrayNotHasKey('manager', $payload);
        // The brand object and the rest of the contract are untouched.
        $this->assertArrayHasKey('brand', $payload);
        $this->assertArrayHasKey('prices', $payload);
        $this->assertArrayHasKey('lastPriceUpdate', $payload);
    }

    /**
     * The administrator endpoints still carry the manager object - the dashboard's
     * station table renders the name from it (admin.js managerCell).
     */
    public function test_admin_station_payload_still_carries_the_manager(): void
    {
        $this->seedBanguedData();
        [$station, $manager] = $this->assignAManagerToAPublishedStation();

        $admin = User::where('role', 'admin')->firstOrFail();

        $this->actingAs($admin)
            ->getJson('/api/admin/stations')
            ->assertOk();

        $row = collect($this->actingAs($admin)->getJson('/api/admin/stations')->json('data'))
            ->firstWhere('id', $station->id);

        $this->assertIsArray($row, 'the assigned station must appear in the admin list');
        $this->assertSame($manager->username, $row['manager']['username']);
        $this->assertSame($manager->display_name, $row['manager']['displayName']);
    }

    /**
     * A station_manager holding one published station of their own brand.
     *
     * Built the way AdminApiController::createManager() builds one - the model
     * exposes no setPassword path through create(), so a plain User::create()
     * leaves password_hash NULL and the NOT NULL constraint rejects it.
     *
     * @return array{0: Station, 1: User}
     */
    private function assignAManagerToAPublishedStation(): array
    {
        $this->seedBanguedData();

        $brand = Brand::where('slug', 'shell')->firstOrFail();

        $manager = new User([
            'username' => 'owner@example.test',
            'email' => 'owner@example.test',
            'display_name' => 'Station Owner',
            'role' => 'station_manager',
            'brand_id' => $brand->id,
        ]);
        $manager->setPasswordHash('password123');
        $manager->save();

        $station = Station::where('is_active', true)->where('is_pending', false)->firstOrFail();
        $station->forceFill(['manager_id' => $manager->id])->save();

        return [$station->fresh(), $manager];
    }
}
