# DecalDesk — Насоки за разработка

Този файл съдържа официалните изисквания и най-добри практики, които важат за
разработката на плъгина DecalDesk (WooCommerce разширение). Идентификация на
плъгина: текстов домейн `decaldesk`, автор DecalDesk, лицензиране и ъпдейти
през Freemius (виж `decaldesk.php`).

---

## 0. Работен процес — задължителен бекъп преди необратими действия

**Преди всяка необратима промяна/действие (напр. `git merge` в `main`,
`git push --force`, изтриване на branch, `git reset --hard`, drop на DB
таблица, изтриване на файлове/данни) задължително се прави бекъп first.**

Пример за практиката, установена в този проект: преди merge на PR в `main`
се създава backup branch от текущия SHA на `main` (напр.
`backup/main-before-pr-<N>-<дата>`), едва след това се извършва merge-ът.
Същият принцип важи за всяко друго необратимо или трудно обратимо действие.

---

## 1. Основни архитектурни изисквания

### 1.1. Стандарти за писане на код
- PHP, CSS и JS кодът спазва официалните WordPress Coding Standards.
- Препоръчва се PHP_CodeSniffer с WooCommerce-специфични правила.
- Всички функции, класове, константи и глобални променливи носят уникален
  префикс (напр. `decaldesk_` / `DecalDesk`), за да се избегнат конфликти.

### 1.2. Проверка за активен WooCommerce
Плъгинът никога не трябва да се инициализира, ако WooCommerce не е активен:
```php
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}
```

### 1.3. Локализация (i18n & l10n)
- Всички видими текстове минават през `__()`, `_e()`, `esc_html__()` и др.
- Текстовият домейн трябва да съвпада с директорията на плъгина (`decaldesk`).

---

## 2. Сигурност

- **Права (Capabilities):** проверка на `current_user_can()` преди действия в
  админ панела.
- **Nonces (CSRF):** `wp_nonce_field()` / `wp_verify_nonce()` за всяка форма
  или AJAX заявка, която променя данни.
- **Саниране на вход:** `sanitize_text_field()`, `absint()`,
  `sanitize_textarea_field()`, `sanitize_file_name()`.
- **Ескейпване на изход:** `esc_html()`, `esc_attr()`, `esc_url()`,
  `wp_kses_post()` — непосредствено преди извеждане на екрана.
- Prepared statements (`$wpdb->prepare()`) при директни SQL заявки — да се
  предпочитат WordPress/WooCommerce API функции пред директен SQL.

---

## 3. HPOS (High-Performance Order Storage)

- Задължително деклариране на съвместимост в `before_woocommerce_init`:
  ```php
  add_action( 'before_woocommerce_init', function() {
      if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
          \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
      }
  } );
  ```
- **Забранено:** `get_post_meta()`/`update_post_meta()`/`delete_post_meta()`
  за поръчки, директни SQL заявки към `wp_posts`/`wp_postmeta` за поръчки.
- **Задължително:** CRUD API — `wc_get_order()`, `$order->get_meta()`,
  `$order->update_meta_data()`, `$order->save()`.

---

## 4. Cart & Checkout Blocks

- Старите shortcode-базирани хукове (напр. `woocommerce_after_checkout_form`)
  не работят в React блоковете — за разширяване се ползва WooCommerce Blocks
  API и JavaScript (React).
- Комуникация в реално време фронтенд↔бекенд минава през Store API.
- В хедъра на основния файл се поддържа актуален `WC tested up to:` таг.

---

## 5. Производителност и база данни

- Предпочитане на стандартните WooCommerce структури пред собствени таблици.
- Бавни/AI операции никога не се изпълняват синхронно при зареждане на
  страница — използва се **Action Scheduler** за фонови задачи и
  **Transient API** за кеширане на скъпи резултати.
- **Conditional Loading:** CSS/JS се зареждат само на страниците, където са
  нужни (филтриране по `$hook_suffix` в админ панела).

---

## 6. Тестване и качество

- Разработка с `WP_DEBUG` / `WP_DEBUG_LOG` включени, `WP_DEBUG_DISPLAY` = false.
- Unit тестове за бизнес логиката.
- Статичен анализ с PHPStan, стилово валидиране с PHP_CodeSniffer.
- Ако плъгинът се качва в официалния Marketplace — трябва да минава QIT
  (Activation, Security, Malware, PHPCompatibility, Woo API, E2E с
  Playwright).

---

## 7. Файлова структура (референтна, за нови модули)

```text
admin/          # Логика и изгледи за административния панел (CSS/JS/class-admin.php)
public/         # Логика и изгледи за фронтенда (CSS/JS/class-public.php)
includes/       # Ядро — общи класове, функции, i18n
languages/      # .pot/.po/.mo
assets/         # Медия, икони, лога
decaldesk.php   # Bootstrap файл
uninstall.php   # Безопасно почистване при изтриване
```

### Bootstrap файл
- Plugin Header с метаданни.
- Защита от директен достъп: `defined( 'ABSPATH' ) || exit;`
- Константи за версия/пътища: `DECALDESK_VERSION`, `..._PATH`, `..._URL`.

### Жизнен цикъл
- `register_activation_hook`: еднократни действия (DB таблици чрез
  `dbDelta`, настройки по подразбиране, `flush_rewrite_rules()`).
- `register_deactivation_hook`: спиране на cron/фонови задачи — **без**
  триене на потребителски данни.
- `uninstall.php`: пълно почистване (опции, метаданни, custom таблици) само
  при реално изтриване от админ панела.

---

## 8. Изисквания за WooCommerce Marketplace (Woo.com)

Приложимо, ако плъгинът се разпространява през официалния Woo.com Marketplace.

### 8.1. Техническа съвместимост
- Поддръжка на поне двете най-нови major версии на WooCommerce и WordPress.
- PHP: минимум 7.4+, препоръчително тестван и оптимизиран за 8.3+.
- HPOS съвместимост декларирана (виж Раздел 3); никакви директни SQL/
  `get_post_meta()` за поръчки.
- Cart & Checkout Blocks: JS разширения през Blocks API + Store API.
- QIT: Activation, Security (XSS/SQLi), Malware, PHPCompatibility, Woo API,
  E2E (Playwright) тестове при всяка версия.

### 8.2. Лицензиране и монетизация
- Не се допускат напълно безплатни продукти — нужен е монетизационен модел.
- Модели: Paid License (single-site) или Freemium (безплатна база в
  WordPress.org + платен ъпгрейд в Marketplace); интеграционни плъгини
  следват ценообразуването на свързаната SaaS платформа.
- Revenue share: 70% разработчик / 30% Marketplace.
- Best Price Policy: цената в Marketplace ≤ цената другаде.

### 8.3. SaaS Billing API (при абонаментен модел)
- Задължителна интеграция с официалното SaaS Billing API за периодични
  плащания, free trials, еднократни такси (`POST /charges`), смяна на план
  (upgrade/downgrade/crossgrade) с пропорционално таксуване (proration).
- Sandbox: `https://sandbox.woocommerce.com/`, API база
  `https://sandbox.woocommerce.com/wp-json/wccom/billing/1.0/`.
- `return_url` не приема `localhost` — за локална разработка се мапва домейн
  (напр. `app.local`) в hosts файла.
- Интерфейс за отказ от абонамент → `DELETE` към Billing API; достъпът се
  запазва до края на предплатения период.
- Webhook събития (хедър `x-wc-webhook-topic`):
  `saas_billing_contract.activated`, `.updated`, `.renewed`, `.paused`,
  `.canceled`, `.prepaid_term_ended`, `.refunded`.
- Партньорско споразумение (алтернатива за компании със собствена billing
  инфраструктура) — изисква индивидуално одобрение от WooCommerce.

### 8.4. Съдържание и стил
- Активен залог (Active Voice), Sentence case за заглавия, Oxford comma.
- Правопис: "WooCommerce" (главни W, C), "ecommerce" (малки букви, без тире).
- Име на продукта не започва с "WooCommerce" — формат
  "[Име на продукта] for WooCommerce".
- Продуктова икона: 160×160px JPG/PNG (показва се 80×80px), логото на
  продукта (не на фирмата), без текст, safe zone 112×112px, ъглите не се
  заоблят ръчно.
- Скрийншотове: модерна WP admin цветова схема, без сложни конфигурационни
  екрани, оптимизирани за уеб, с alt text.
- Видео галерия: максимум едно видео (YouTube/Vimeo), позиционира се първо.

### 8.5. Поддръжка и SLA
- Поддръжка само по имейл (live chat-ът пренасочва към регистрирания имейл).
- Своевременни отговори; предварително уведомяване на WooCommerce при
  планирано отсъствие.
- Проследяване на support ratings (7 дни след тикет) и feature requests в
  коментарите на продуктовата страница.

### 8.6. Акаунт и актуализации
- Управление на акаунта през Partner Dashboard.
- Продуктите се обновяват минимум веднъж на 6 месеца (иначе се флагват за
  премахване). Препоръчителен ритъм: Major — на тримесечие (с WC Core),
  Minor — ежемесечно, security патчове — незабавно.
- Всеки архив съдържа `changelog.txt` в корена; версията в PHP хедъра трябва
  да съвпада с последната версия в `changelog.txt` и версията при качване.
