<?php

declare(strict_types=1);

namespace Fastleo\Leads;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Override;

/**
 * Заявка с формы сайта (`POST /leads`): проверка полей и защита от ботов без внешних сервисов.
 *
 * Форма отправляет `name`, `phone`, необязательные `email` и `message`, скрытое поле-ловушку `leave_empty` (человеку его не видно,
 * оно должно остаться пустым) и `form_token` — `FormToken::make()`. Готовая форма — `<x-fastleo::lead-form />`.
 */
final class StoreLeadRequest extends FormRequest
{
    /** Быстрее этого числа секунд после показа формы человек её не отправит. */
    private const int MIN_SECONDS = 3;

    /** Форма старше суток считается устаревшей. */
    private const int MAX_SECONDS = 86400;

    /** Текст без байта NUL и в верном UTF-8: иначе PostgreSQL отвечает ошибкой 500. */
    public const string TEXT = 'regex:/\A[^\x00]*\z/u';

    /** Телефон: цифры, плюс, скобки, дефис, пробел и точка. */
    public const string PHONE = 'regex:/^[0-9+()\- .]{5,}$/D';

    /**
     * Правила полей.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', self::TEXT],
            'phone' => ['required', 'string', 'max:50', self::PHONE],
            'email' => ['bail', 'nullable', 'string', 'max:255', 'email'],
            'message' => ['nullable', 'string', 'max:2000', self::TEXT],
            'form' => ['nullable', 'string', 'max:500', self::TEXT],
            'leave_empty' => ['nullable', 'string', 'max:255'],
            'form_token' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Заявку отправил бот: заполнено скрытое поле-ловушка либо нет метки времени формы (или она подделана).
     * Такую заявку молча отбрасывают.
     */
    public function isBot(): bool
    {
        return $this->filled('leave_empty') || $this->shownToken() === null;
    }

    /**
     * Форма отправлена слишком быстро, давно или с чужой сессии: бывает и у живого посетителя (автозаполнение, долго открытая
     * вкладка, вход на сайте), поэтому ему показывают ошибку, а не «Спасибо!».
     */
    public function isOutOfTime(): bool
    {
        $token = $this->shownToken();

        if ($token === null) {
            return false;
        }

        $seconds = now()->getTimestamp() - $token['shown_at'];

        return $seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS || ! hash_equals($this->session()->getId(), $token['session']);
    }

    /**
     * Якорь формы, с которой пришла заявка (`<x-fastleo::lead-form anchor="…" />`); нет или неверный — `null`.
     */
    public function anchor(): ?string
    {
        return Leads::anchor($this->input('form_anchor'));
    }

    /**
     * Набор ошибок формы с якорем (`lead_{якорь}`): у каждой формы страницы свои ошибки; без якоря — набор по умолчанию.
     */
    public function bag(): string
    {
        $anchor = $this->anchor();

        return $anchor === null ? 'default' : 'lead_'.$anchor;
    }

    /**
     * Название формы или тема заявки из скрытого поля `form`: одна строка, до 100 знаков; пусто — `null`.
     * Посетитель ошибок скрытого поля не видит, поэтому лишнее отрезается, а не отклоняется.
     */
    public function formName(): ?string
    {
        return Leads::formName($this->input('form'));
    }

    /**
     * Ошибки проверки уходят в набор формы с якорем (`bag()`) и на страницу формы с фрагментом: ответ собирается здесь, а не обработчиком
     * исключений Laravel, который берёт имя набора из поля запроса `_error_bag` — посетитель мог бы назвать его как угодно.
     */
    #[Override]
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(redirect($this->getRedirectUrl())->withInput()->withErrors($validator->errors(), $this->bag()));
    }

    /**
     * Адрес возврата при ошибке проверки: страница, с которой отправлена форма, и фрагмент `#form-{якорь}` — посетитель
     * остаётся у своей формы, а не наверху длинной страницы.
     */
    #[Override]
    protected function getRedirectUrl(): string
    {
        $url = parent::getRedirectUrl();
        $anchor = $this->anchor();

        return $anchor === null ? $url : $url.'#form-'.$anchor;
    }

    /**
     * Метка формы или null, если её нет или она подделана.
     *
     * @return array{shown_at: int, session: string}|null
     */
    private function shownToken(): ?array
    {
        $token = $this->input('form_token');

        return FormToken::read(is_string($token) ? $token : null);
    }
}
