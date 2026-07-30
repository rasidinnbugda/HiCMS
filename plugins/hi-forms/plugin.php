<?php

declare(strict_types=1);

namespace HiForms;

use HiCMS\Content\BlockRenderer;
use HiCMS\Events\Admin\MenuBuilding;
use HiCMS\Http\Response;
use HiCMS\Http\Router;
use HiCMS\Plugin\Plugin as BasePlugin;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;

/**
 * HiForms — form oluşturucu ve gönderi kutusu
 *
 * Form tanımları ayarlarda, gönderiler kendi tablosunda tutulur. İçeriğe
 * yerleştirme çekirdeğin blok API'siyle yapılır: eklenti bir `form` bloğu
 * kaydeder, blok editöründe kendiliğinden görünür.
 *
 * Spam koruması iki katmanlı: gizli bal küpü alanı + aynı IP'den kısa aralıklı
 * gönderim sınırı. Harici servis kullanılmaz.
 */
final class Plugin extends BasePlugin
{
    public function boot(): void
    {
        // Blok: içeriğe form yerleştirme
        hi_register_block('form', [
            'label'       => 'Form',
            'icon'        => 'inbox',
            'group'       => 'düzen',
            'description' => 'Panelde tanımlı bir formu içeriğe yerleştirir.',
            'fields'      => [
                [
                    'key'     => 'form',
                    'type'    => 'select',
                    'label'   => 'Form',
                    'options' => $this->formOptions(),
                ],
            ],
            'render' => fn(array $data, BlockRenderer $renderer): string
                => $this->renderForm((string) ($data['form'] ?? '')),
        ]);

        // Gönderim rotası
        hi_on('routing.register', function (Router $router): void {
            $router->post('/form-gonder', fn(array $params, $request): Response
                => $this->handleSubmit($request), 'forms.submit', 2);
        });

        // Panel
        hi_listen(MenuBuilding::class, function (MenuBuilding $event): void {
            if (!hi()->auth()->can('settings.manage')) {
                return;
            }

            $unread = $this->unreadCount();

            $event->add('content', [
                'slug'  => 'plugin:hi-forms',
                'label' => 'Formlar',
                'icon'  => 'inbox',
                'url'   => 'plugin.php?eklenti=hi-forms',
                'badge' => $unread > 0 ? $unread : null,
                'alert' => true,
            ]);
        });

        hi_on('admin.page.hi-forms', fn(): null => $this->screen());
    }

    public function activate(): void
    {
        if ($this->forms() === []) {
            $this->setOption('forms', [
                'iletisim' => [
                    'name'   => 'İletişim',
                    'notify' => (string) hi_option('admin_email', ''),
                    'success' => 'Mesajınız alındı. En kısa sürede dönüş yapacağız.',
                    'fields' => [
                        ['key' => 'ad', 'label' => 'Adınız', 'type' => 'text', 'required' => true],
                        ['key' => 'eposta', 'label' => 'E-posta', 'type' => 'email', 'required' => true],
                        ['key' => 'konu', 'label' => 'Konu', 'type' => 'text', 'required' => false],
                        ['key' => 'mesaj', 'label' => 'Mesajınız', 'type' => 'textarea', 'required' => true],
                    ],
                ],
            ]);
        }
    }

    /* ---------------------------------------------------------------------
     * Tanımlar
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, array<string, mixed>>
     */
    public function forms(): array
    {
        $stored = $this->option('forms', []);

        return is_array($stored) ? array_filter($stored, 'is_array') : [];
    }

    /**
     * @return array<string, string>
     */
    private function formOptions(): array
    {
        $options = [];

        foreach ($this->forms() as $slug => $form) {
            $options[(string) $slug] = (string) ($form['name'] ?? $slug);
        }

        return $options !== [] ? $options : ['' => 'Tanımlı form yok'];
    }

    /* ---------------------------------------------------------------------
     * Ön yüz
     * ------------------------------------------------------------------ */

    private function renderForm(string $slug): string
    {
        $form = $this->forms()[$slug] ?? null;

        if ($form === null) {
            return '';
        }

        $sent = (string) (hi()->request()->query('form') ?? '');
        $html = '<div class="block-form">';

        if ($sent === $slug) {
            $html .= '<p class="form-note">'
                . Str::html((string) ($form['success'] ?? 'Gönderiniz alındı.')) . '</p>';
        } elseif ($sent === $slug . '-hata') {
            $html .= '<p class="form-note">'
                . Str::html((string) (hi()->request()->query('mesaj') ?? 'Form gönderilemedi.')) . '</p>';
        }

        $html .= '<form method="post" action="' . Str::url(hi()->urls()->to('form-gonder')) . '">'
            . hi()->csrf()->field()
            . '<input type="hidden" name="form" value="' . Str::attr($slug) . '">'
            . '<div style="position:absolute;left:-9999px" aria-hidden="true">'
            . '<label for="hf-tuzak">Web adresi</label>'
            . '<input type="text" id="hf-tuzak" name="tuzak" tabindex="-1" autocomplete="off"></div>';

        foreach ((array) ($form['fields'] ?? []) as $field) {
            $key      = (string) ($field['key'] ?? '');
            $label    = (string) ($field['label'] ?? $key);
            $type     = (string) ($field['type'] ?? 'text');
            $required = !empty($field['required']);
            $id       = 'hf-' . Str::slug($slug . '-' . $key);

            if ($key === '') {
                continue;
            }

            $html .= '<div class="field"><label for="' . Str::attr($id) . '">' . Str::html($label)
                . ($required ? ' <span class="req">*</span>' : '') . '</label>';

            $html .= match ($type) {
                'textarea' => '<textarea class="textarea" id="' . Str::attr($id) . '" name="alan['
                    . Str::attr($key) . ']"' . ($required ? ' required' : '') . '></textarea>',
                'select' => '<select class="input" id="' . Str::attr($id) . '" name="alan['
                    . Str::attr($key) . ']"' . ($required ? ' required' : '') . '>'
                    . implode('', array_map(
                        static fn(string $option): string => '<option value="' . Str::attr($option) . '">'
                            . Str::html($option) . '</option>',
                        array_map('strval', (array) ($field['options'] ?? []))
                    )) . '</select>',
                default => '<input class="input" type="' . Str::attr(in_array($type, ['email', 'tel', 'url', 'number'], true)
                    ? $type : 'text') . '" id="' . Str::attr($id) . '" name="alan['
                    . Str::attr($key) . '"' . ($required ? ' required' : '') . '>',
            };

            $html .= '</div>';
        }

        $html .= '<div class="field"><label class="consent"><input type="checkbox" name="onay" required>'
            . '<span>Verdiğim bilgilerin bu form talebini yanıtlamak amacıyla saklanmasını kabul ediyorum.</span>'
            . '</label></div>'
            . '<button class="button button-primary" type="submit">Gönder</button>'
            . '</form></div>';

        return $html;
    }

    private function handleSubmit(mixed $request): Response
    {
        $slug = (string) $request->text('form');
        $form = $this->forms()[$slug] ?? null;
        $back = $request->referer() !== '' ? $request->referer() : hi()->urls()->to();

        if ($form === null) {
            return Response::redirect($back)->withStatus(303);
        }

        $fail = static fn(string $message): Response => Response::redirect(
            $back . (str_contains($back, '?') ? '&' : '?')
            . 'form=' . rawurlencode($slug) . '-hata&mesaj=' . rawurlencode($message)
        )->withStatus(303);

        if (!hi()->csrf()->verify($request->text('_token'))) {
            return $fail('Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.');
        }

        // Bal küpü: doldurulmuşsa sessizce başarılı görün.
        if ($request->text('tuzak') !== '') {
            return Response::redirect($back . (str_contains($back, '?') ? '&' : '?')
                . 'form=' . rawurlencode($slug))->withStatus(303);
        }

        $db = hi()->db();

        if ($db->tableExists('form_submissions')) {
            $recent = $db->builder('form_submissions')
                ->where('ip', $request->ip())
                ->whereRaw('created_at > :since', ['since' => Dates::stamp('-60 seconds')])
                ->count();

            if ($recent >= 2) {
                return $fail('Çok hızlı gönderim yapıyorsunuz, biraz bekleyin.');
            }
        }

        $payload = [];

        foreach ((array) ($form['fields'] ?? []) as $field) {
            $key = (string) ($field['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $value = trim((string) ($request->input('alan')[$key] ?? ''));

            if (!empty($field['required']) && $value === '') {
                return $fail(Str::format('"%s" alanı zorunludur.', (string) ($field['label'] ?? $key)));
            }

            if (($field['type'] ?? '') === 'email' && $value !== ''
                && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                return $fail('Geçerli bir e-posta adresi girin.');
            }

            $payload[$key] = mb_substr($value, 0, 5000);
        }

        if (!$db->tableExists('form_submissions')) {
            return $fail('Gönderi tablosu bulunamadı. Eklentiyi yeniden etkinleştirin.');
        }

        $db->insert('form_submissions', [
            'form'       => $slug,
            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'subject'    => mb_substr((string) ($payload['konu'] ?? ($payload['ad'] ?? 'Yeni gönderi')), 0, 250),
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => Dates::stamp(),
        ]);

        // Bildirim e-postası — sunucuda mail() varsa gönderilir, yoksa sessizce atlanır.
        $notify = trim((string) ($form['notify'] ?? ''));

        if ($notify !== '' && function_exists('mail')) {
            $lines = [];

            foreach ($payload as $key => $value) {
                $lines[] = $key . ': ' . $value;
            }

            @mail(
                $notify,
                '[' . hi_site_name() . '] ' . (string) ($form['name'] ?? $slug),
                implode("\n", $lines),
                'Content-Type: text/plain; charset=UTF-8'
            );
        }

        hi()->events()->emit('forms.submitted', $slug, $payload);

        return Response::redirect($back . (str_contains($back, '?') ? '&' : '?')
            . 'form=' . rawurlencode($slug))->withStatus(303);
    }

    private function unreadCount(): int
    {
        $db = hi()->db();

        return $db->tableExists('form_submissions')
            ? $db->builder('form_submissions')->where('is_read', 0)->count()
            : 0;
    }

    /* ---------------------------------------------------------------------
     * Panel
     * ------------------------------------------------------------------ */

    private function screen(): null
    {
        $selfUrl = 'plugin.php?eklenti=hi-forms';
        $db      = hi()->db();

        if (hi()->request()->isPost()) {
            admin_verify($selfUrl);

            $action = (string) ($_POST['islem'] ?? '');

            if ($action === 'save-form') {
                $slug = Str::slug((string) ($_POST['kisa_ad'] ?? ''));

                if ($slug === '') {
                    admin_redirect($selfUrl, 'error', 'Form kısa adı zorunludur.');
                }

                $fields = [];

                foreach ((array) ($_POST['alan'] ?? []) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $key = Str::slug((string) ($row['key'] ?? ''), '_');

                    if ($key === '') {
                        continue;
                    }

                    $type = (string) ($row['type'] ?? 'text');

                    $fields[] = [
                        'key'      => $key,
                        'label'    => trim((string) ($row['label'] ?? $key)),
                        'type'     => in_array($type, ['text', 'email', 'tel', 'url', 'number', 'textarea'], true)
                            ? $type : 'text',
                        'required' => !empty($row['required']),
                    ];
                }

                $forms        = $this->forms();
                $forms[$slug] = [
                    'name'    => trim((string) ($_POST['ad'] ?? $slug)),
                    'notify'  => trim((string) ($_POST['bildirim'] ?? '')),
                    'success' => trim((string) ($_POST['basari'] ?? 'Gönderiniz alındı.')),
                    'fields'  => $fields,
                ];

                $this->setOption('forms', $forms);

                admin_redirect($selfUrl, 'success', 'Form kaydedildi. Blok editöründe "Form" bloğuyla yerleştirin.');
            }

            if ($action === 'delete-form') {
                $forms = $this->forms();
                unset($forms[(string) ($_POST['kisa_ad'] ?? '')]);
                $this->setOption('forms', $forms);

                admin_redirect($selfUrl, 'success', 'Form silindi. Gönderiler korundu.');
            }

            if ($action === 'mark-read' && $db->tableExists('form_submissions')) {
                $db->builder('form_submissions')->where('is_read', 0)->update(['is_read' => 1]);

                admin_redirect($selfUrl, 'success', 'Gönderiler okundu olarak işaretlendi.');
            }

            if ($action === 'delete-submission' && $db->tableExists('form_submissions')) {
                $db->delete('form_submissions', ['id' => (int) ($_POST['id'] ?? 0)]);

                admin_redirect($selfUrl, 'success', 'Gönderi silindi.');
            }
        }

        $forms       = $this->forms();
        $editSlug    = (string) ($_GET['duzenle'] ?? '');
        $editing     = $forms[$editSlug] ?? null;
        $submissions = $db->tableExists('form_submissions')
            ? $db->builder('form_submissions')->orderBy('id', 'desc')->limit(50)->get()
            : [];

        admin_head([
            'title'       => 'Formlar',
            'slug'        => 'plugin:hi-forms',
            'description' => 'Form tanımları ve gelen gönderiler.',
            'breadcrumb'  => [['label' => 'Eklentiler', 'url' => 'plugins.php'], ['label' => 'HiForms']],
            'actions'     => $submissions !== []
                ? '<form method="post" action="' . esc_url($selfUrl) . '" style="display:inline">'
                    . hi_csrf_field() . '<input type="hidden" name="islem" value="mark-read">'
                    . '<button class="btn" type="submit">Tümünü okundu işaretle</button></form>'
                : '',
        ]);
        ?>

        <div class="cols-main">
            <div>
                <section class="panel">
                    <header class="panel-head">
                        <div>
                            <h2 class="panel-title">Gönderiler</h2>
                            <p class="panel-sub">Son 50 kayıt</p>
                        </div>
                    </header>

                    <?php if ($submissions !== []) : ?>
                        <div class="table-wrap">
                            <table class="data">
                                <thead>
                                    <tr>
                                        <th>Form</th>
                                        <th>İçerik</th>
                                        <th>Tarih</th>
                                        <th class="fit"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($submissions as $row) : ?>
                                        <?php
                                        $payload = json_decode((string) $row['payload'], true);
                                        $payload = is_array($payload) ? $payload : [];
                                        ?>
                                        <tr>
                                            <td class="small">
                                                <?= esc_html((string) ($forms[$row['form']]['name'] ?? $row['form'])) ?>
                                                <?php if ((int) $row['is_read'] === 0) : ?>
                                                    <span class="pill is-warn no-dot">yeni</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small dim" style="max-width:440px">
                                                <?php foreach (array_slice($payload, 0, 4) as $key => $value) : ?>
                                                    <div><strong><?= esc_html((string) $key) ?>:</strong>
                                                        <?= esc_html(Str::limit((string) $value, 90)) ?></div>
                                                <?php endforeach; ?>
                                            </td>
                                            <td class="small muted nowrap"><?= ui_time((string) $row['created_at']) ?></td>
                                            <td class="fit">
                                                <form method="post" action="<?= esc_url($selfUrl) ?>">
                                                    <?= hi_csrf_field() ?>
                                                    <input type="hidden" name="islem" value="delete-submission">
                                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                                    <button class="icon-btn" type="submit" aria-label="Sil"
                                                        <?= ui_confirm('Bu gönderi silinecek.') ?>>
                                                        <?= admin_icon('trash', 15) ?>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else : ?>
                        <?= ui_empty('inbox', 'Gönderi yok',
                            'Formu bir içeriğe yerleştirin; gelen gönderiler burada birikecek.') ?>
                    <?php endif; ?>
                </section>

                <section class="panel">
                    <header class="panel-head">
                        <div>
                            <h2 class="panel-title">Tanımlı formlar</h2>
                            <p class="panel-sub">İçeriğe "Form" bloğuyla yerleştirilir</p>
                        </div>
                    </header>
                    <div class="panel-body col">
                        <?php foreach ($forms as $slug => $form) : ?>
                            <div class="node">
                                <div class="node-body">
                                    <strong><?= esc_html((string) $form['name']) ?></strong>
                                    <small><?= esc_html((string) $slug) ?> ·
                                        <?= count((array) ($form['fields'] ?? [])) ?> alan</small>
                                </div>
                                <a class="btn btn-sm" href="<?= esc_url($selfUrl . '&duzenle=' . rawurlencode((string) $slug)) ?>">
                                    Düzenle
                                </a>
                                <form method="post" action="<?= esc_url($selfUrl) ?>">
                                    <?= hi_csrf_field() ?>
                                    <input type="hidden" name="islem" value="delete-form">
                                    <input type="hidden" name="kisa_ad" value="<?= esc_attr((string) $slug) ?>">
                                    <button class="icon-btn" type="submit" aria-label="Sil"
                                        <?= ui_confirm('Form tanımı silinecek. Gönderiler korunur.') ?>>
                                        <?= admin_icon('trash', 15) ?>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($forms === []) : ?>
                            <p class="muted small mb-0">Henüz form yok.</p>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <div>
                <form class="box" method="post" action="<?= esc_url($selfUrl) ?>">
                    <?= hi_csrf_field() ?>
                    <input type="hidden" name="islem" value="save-form">

                    <header class="box-head">
                        <?= admin_icon('inbox', 15) ?>
                        <?= $editing !== null ? 'Formu düzenle' : 'Yeni form' ?>
                    </header>

                    <div class="box-body">
                        <?= ui_field('Form adı', ui_input('ad', (string) ($editing['name'] ?? ''),
                            ['id' => 'f-ad', 'required' => true, 'placeholder' => 'İletişim']), '', 'f-ad', true) ?>

                        <?= ui_field('Kısa ad', ui_input('kisa_ad', $editSlug,
                            ['id' => 'f-slug', 'class' => 'input mono', 'required' => true,
                             'placeholder' => 'iletisim']),
                            'Blok editöründe bu adla görünür.', 'f-slug', true) ?>

                        <?= ui_field('Bildirim e-postası', ui_input('bildirim',
                            (string) ($editing['notify'] ?? hi_option('admin_email', '')),
                            ['type' => 'email', 'id' => 'f-notify']),
                            'Yeni gönderi geldiğinde bu adrese bilgi gider.', 'f-notify') ?>

                        <?= ui_field('Başarı mesajı', ui_input('basari',
                            (string) ($editing['success'] ?? 'Mesajınız alındı.'), ['id' => 'f-success']),
                            '', 'f-success') ?>

                        <hr>
                        <p class="label">Alanlar</p>

                        <?php
                        $fields = (array) ($editing['fields'] ?? [
                            ['key' => 'ad', 'label' => 'Adınız', 'type' => 'text', 'required' => true],
                            ['key' => 'eposta', 'label' => 'E-posta', 'type' => 'email', 'required' => true],
                            ['key' => 'mesaj', 'label' => 'Mesajınız', 'type' => 'textarea', 'required' => true],
                        ]);

                        for ($i = 0; $i < 8; $i++) :
                            $field = $fields[$i] ?? [];
                            ?>
                            <div class="node" style="display:block;padding:10px 12px">
                                <div class="field-row" style="gap:8px">
                                    <?= ui_input('alan[' . $i . '][key]', (string) ($field['key'] ?? ''),
                                        ['class' => 'input mono', 'placeholder' => 'anahtar',
                                         'aria-label' => 'Alan anahtarı ' . ($i + 1)]) ?>
                                    <?= ui_input('alan[' . $i . '][label]', (string) ($field['label'] ?? ''),
                                        ['placeholder' => 'Etiket', 'aria-label' => 'Alan etiketi ' . ($i + 1)]) ?>
                                </div>
                                <div class="field-row mt-1" style="gap:8px;align-items:center">
                                    <?= ui_select('alan[' . $i . '][type]', [
                                        'text' => 'Metin', 'email' => 'E-posta', 'tel' => 'Telefon',
                                        'url' => 'Adres', 'number' => 'Sayı', 'textarea' => 'Uzun metin',
                                    ], (string) ($field['type'] ?? 'text'),
                                        ['aria-label' => 'Alan türü ' . ($i + 1)]) ?>

                                    <label class="check">
                                        <input type="checkbox" name="alan[<?= $i ?>][required]" value="1"
                                            <?= !empty($field['required']) ? 'checked' : '' ?>>
                                        <span class="check-body">Zorunlu</span>
                                    </label>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <footer class="box-foot">
                        <?php if ($editing !== null) : ?>
                            <a class="btn btn-sm" href="<?= esc_url($selfUrl) ?>">Vazgeç</a>
                        <?php endif; ?>
                        <span class="spacer"></span>
                        <button class="btn btn-sm btn-primary" type="submit" data-primary-save>Kaydet</button>
                    </footer>
                </form>
            </div>
        </div>

        <?php
        admin_foot();

        return null;
    }
}
