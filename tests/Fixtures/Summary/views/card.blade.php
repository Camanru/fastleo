<p data-summary>Первая статья: {{ $record->articles()->orderBy('id')->value('title') ?? 'нет' }}</p>
