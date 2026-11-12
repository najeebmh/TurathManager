<?php

declare(strict_types=1);

namespace TurathManager\Tests;

/**
 * المرحلة 4: التصنيفات والأنواع والموديلات والصور وحركة المخزون.
 */
final class CatalogTest extends TestCase
{
    public function testUserScenarioMoroccanSijafAndSanaaniKosha(): void
    {
        $t = $this->admin();
        $sijaf = $this->ok('POST', '/categories', ['name' => 'سجاف'], $t)['data'];
        $kosha = $this->ok('POST', '/categories', ['name' => 'كوش'], $t)['data'];
        $moroccan = $this->ok('POST', '/category-types', ['category_id' => $sijaf['id'], 'name' => 'مغربي'], $t)['data'];
        $sanaani = $this->ok('POST', '/category-types', ['category_id' => $kosha['id'], 'name' => 'صنعاني'], $t)['data'];
        $this->ok('POST', '/category-types', ['category_id' => $kosha['id'], 'name' => 'عرائسي'], $t);
        $this->ok('POST', '/category-types', ['category_id' => $kosha['id'], 'name' => 'صالة أفراح'], $t);

        $p1 = $this->ok('POST', '/products', ['name' => 'سجاف مغربي ذهبي', 'code' => 'SJ-001', 'category_id' => $sijaf['id'], 'category_type_id' => $moroccan['id'], 'price' => '45000', 'initial_quantity' => 3, 'description' => 'تطريز ذهبي'], $t)['data'];
        $p2 = $this->ok('POST', '/products', ['name' => 'كوشة صنعانية ملكية', 'code' => 'KS-001', 'category_id' => $kosha['id'], 'category_type_id' => $sanaani['id'], 'price' => '120000', 'initial_quantity' => 2], $t)['data'];
        $this->assertSame(3, $p1['quantity_total']);
        $this->assertSame(2, $p2['quantity_total']);
        $this->assertSame(3, $p1['available_now']);

        // تعديل الكمية عبر حركة مخزون مسجلة فقط
        [$s] = $this->call('PUT', "/products/{$p1['id']}", ['quantity_total' => 10], $t);
        $this->assertSame(422, $s);
        $p1 = $this->ok('POST', "/products/{$p1['id']}/movements", ['movement_type' => 'purchase', 'quantity' => 2, 'reason' => 'شراء قطعتين'], $t)['data'];
        $this->assertSame(5, $p1['quantity_total']);
        $p1 = $this->ok('POST', "/products/{$p1['id']}/movements", ['movement_type' => 'maintenance_out', 'quantity' => 1, 'reason' => 'صيانة التطريز'], $t)['data'];
        $this->assertSame(4, $p1['quantity_total']);
        $details = $this->ok('GET', "/products/{$p1['id']}", [], $t)['data'];
        $this->assertCount(3, $details['movements']);
        $this->assertSame(4, $details['movements'][0]['balance_after']);
        [$s] = $this->call('POST', "/products/{$p1['id']}/movements", ['movement_type' => 'damage', 'quantity' => 99, 'reason' => 'x'], $t);
        $this->assertSame(422, $s, 'cannot remove more than stock');

        // الصور
        $withImg = $this->call('POST', "/products/{$p1['id']}/images", [], $t, ['image' => $this->image('png')]);
        $this->assertSame(201, $withImg[0], json_encode($withImg[1], JSON_UNESCAPED_UNICODE));
        $this->assertCount(1, $withImg[1]['data']['images']);
        $this->assertTrue($withImg[1]['data']['images'][0]['is_primary']);
        $second = $this->call('POST', "/products/{$p1['id']}/images", [], $t, ['image' => $this->image('jpeg')]);
        $imgs = $second[1]['data']['images'];
        $this->assertCount(2, $imgs);
        $replaced = $this->ok('POST', "/products/{$p1['id']}/images/{$imgs[1]['id']}/primary", [], $t)['data'];
        $this->assertSame($imgs[1]['id'], $replaced['images'][0]['id']);
        $url = $replaced['images'][0]['url'];
        [$s, , $h] = $this->call('GET', '/' . $url);
        $this->assertSame(200, $s);
        $this->assertSame('image/jpeg', $h['Content-Type']);
        $after = $this->ok('DELETE', "/products/{$p1['id']}/images/{$imgs[1]['id']}", [], $t)['data'];
        $this->assertCount(1, $after['images']);
        $this->assertTrue($after['images'][0]['is_primary']);

        // البحث والفلترة والترتيب
        $byName = $this->ok('GET', '/products?search=' . urlencode('مغربي'), [], $t);
        $this->assertSame(1, $byName['total']);
        $this->assertSame('SJ-001', $byName['data'][0]['code']);
        $byCode = $this->ok('GET', '/products?search=KS-001', [], $t);
        $this->assertSame('كوشة صنعانية ملكية', $byCode['data'][0]['name']);
        $byType = $this->ok('GET', '/products?category_type_id=' . $sanaani['id'], [], $t);
        $this->assertSame(1, $byType['total']);
        $byCat = $this->ok('GET', '/products?category_id=' . $sijaf['id'], [], $t);
        $this->assertSame(1, $byCat['total']);
        $sorted = $this->ok('GET', '/products?sort=price_desc', [], $t);
        $this->assertSame(['KS-001', 'SJ-001'], array_column($sorted['data'], 'code'));
        $sortedQ = $this->ok('GET', '/products?sort=qty_asc', [], $t);
        $this->assertSame(['KS-001', 'SJ-001'], array_column($sortedQ['data'], 'code'));
        $this->assertNotNull($sorted['data'][1]['primary_image']);
    }

    public function testTypesAreEditableAndValidatedAgainstCategory(): void
    {
        $t = $this->admin();
        $a = $this->ok('POST', '/categories', ['name' => 'سجاف'], $t)['data'];
        $b = $this->ok('POST', '/categories', ['name' => 'كوش'], $t)['data'];
        $type = $this->ok('POST', '/category-types', ['category_id' => $a['id'], 'name' => 'مغربي'], $t)['data'];
        $renamed = $this->ok('PUT', "/category-types/{$type['id']}", ['category_id' => $a['id'], 'name' => 'مغربي فاخر'], $t)['data'];
        $this->assertSame('مغربي فاخر', $renamed['name']);
        [$s] = $this->call('POST', '/category-types', ['category_id' => $a['id'], 'name' => 'مغربي فاخر'], $t);
        $this->assertSame(422, $s, 'duplicate type name in same category');
        [$s] = $this->call('POST', '/products', ['name' => 'x', 'code' => 'X1', 'category_id' => $b['id'], 'category_type_id' => $type['id']], $t);
        $this->assertSame(422, $s, 'type must belong to category');
        $list = $this->ok('GET', '/categories', [], $t)['data'];
        $this->assertCount(2, $list);
        $this->ok('POST', "/category-types/{$type['id']}/archive", [], $t);
        $list = $this->ok('GET', '/categories', [], $t)['data'];
        $sijaf = array_values(array_filter($list, static fn ($c) => $c['id'] === $a['id']))[0];
        $this->assertCount(0, $sijaf['types']);
    }

    public function testUploadRejectsNonImagesAndOversizedFiles(): void
    {
        $t = $this->admin();
        $p = $this->product($t)['product'];
        $fake = tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($fake, "<?php echo 'pwned'; ?>");
        [$s, $d] = $this->call('POST', "/products/{$p['id']}/images", [], $t, ['image' => ['name' => 'shell.jpg', 'type' => 'image/jpeg', 'tmp_name' => $fake, 'error' => 0, 'size' => 20]]);
        $this->assertSame(422, $s);
        $app = $this->makeApp(['uploads' => ['max_bytes' => 10]]);
        [$s] = $this->call('POST', "/products/{$p['id']}/images", [], $t, ['image' => $this->image()], [], $app);
        $this->assertSame(422, $s);
        [$s] = $this->call('GET', '/media/products/2026/01/../../../.env');
        $this->assertSame(404, $s);
    }

    public function testProductLinkedToBookingIsArchivedNotDeleted(): void
    {
        $t = $this->admin();
        $c = $this->center($t);
        $p = $this->product($t)['product'];
        [$s, $b] = $this->booking($t, $c['id'], [['product_id' => $p['id'], 'quantity' => 1]]);
        $this->assertSame(201, $s);
        [$s, $d] = $this->call('DELETE', "/products/{$p['id']}", [], $t);
        $this->assertSame(409, $s);
        $this->assertSame('has_bookings', $d['error']['code']);
        [$s] = $this->call('POST', "/products/{$p['id']}/archive", [], $t);
        $this->assertSame(409, $s, 'cannot archive with an upcoming booking');
        $this->ok('POST', "/bookings/{$b['data']['id']}/cancel", ['reason' => 'تجربة'], $t);
        $archived = $this->ok('POST', "/products/{$p['id']}/archive", [], $t)['data'];
        $this->assertTrue($archived['archived']);
        $this->assertSame(0, $this->ok('GET', '/products', [], $t)['total']);
        $this->assertSame(1, $this->ok('GET', '/products?status=archived', [], $t)['total']);
    }

    public function testCannotReduceStockBelowFutureReservations(): void
    {
        $t = $this->admin();
        $c = $this->center($t);
        $p = $this->product($t, 'كوش', 'صنعاني', 2)['product'];
        $this->assertSame(201, $this->booking($t, $c['id'], [['product_id' => $p['id'], 'quantity' => 2]])[0]);
        [$s, $d] = $this->call('POST', "/products/{$p['id']}/movements", ['movement_type' => 'damage', 'quantity' => 1, 'reason' => 'تلف'], $t);
        $this->assertSame(409, $s);
        $this->assertSame('reserved_stock', $d['error']['code']);
    }
}
