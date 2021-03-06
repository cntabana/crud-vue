<?php

namespace CrudVue;

use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

class CrudVueCommand extends Command
{
    protected $signature = 'crud-vue
                            {--model= : Specify the Model to convert to Vue}
                            {--path= : Specify the js path of pages}
                            {--stub= : Specify the stub path}
                            {--data=false : Whether or not the model data is accessesed through the `data` attribute}';

    protected $description = 'Converts a Model to an Inertia Vue File';

    public function handle()
    {
        $model = $this->option('model');

        if (empty($model)) {
            if (!$this->confirm('Would you like to generate views from all your models?')) {
                $this->error('Please specify a model');
                $this->info('inertia-vue
                --model= : Specify the Model to convert to Vue
                --path= : Specify the js path of pages (Default: resources/js/Pages)
                --stub= : Specify the stub path (Default: package stub directory)
                --data= : Whether or not the model data is accessesed through the `data` attribute (Default: false)');
                die();
            }

            $this->confirm('Make sure that migrations exist for the models you wish to generate to views.');
        }

        $models = $this->findModels($model);

        $migrations = $this->matchModelsWithMigrations($models);

        $path =  rtrim($this->option('path') ?? resource_path('js/Pages'), '/');
        $stub =  rtrim($this->option('stub') ?? (__DIR__ . '/stubs'), '/');

        $migrations->each(function ($file) use ($stub, $path) {
            $model = Str::after(Str::before($file->getFilenameWithoutExtension(), '_table'), '_create_');

            preg_match_all('/\$table->([A-Za-z]+)\(\'([A-Za-z_+]+)\'\);/', $file->getContents(), $matches);

            $fields = collect($matches[1])->zip($matches[2]);

            $primaryKey = $fields->shift();

            $buildFields = $this->buildFields($fields);

            [$indexVueFile, $editVueFile, $createVueFile, $showVueFile] = $this->replaceWithData([
                '{{fields-head}}' => $buildFields->pluck('{{fields-head}}')->join(''),
                '{{fields-view-data}}' => $buildFields->pluck('{{fields-view-data}}')->join(''),
                '{{fields-data}}' => $buildFields->pluck('{{fields-data}}')->join(''),
                '{{fields-show-data}}' => $buildFields->pluck('{{fields-show-data}}')->join(''),
                '{{input-fields}}' => $buildFields->pluck('{{input-fields}}')->join(''),
                '{{data-form-input}}' => $buildFields->pluck('{{data-form-input}}')->join(''),
                '{{data-form-input-null}}' => $buildFields->pluck('{{data-form-input-null}}')->join(''),
                '{{data-attribute}}' => empty($this->option('data')) ? '' : '.data'
            ], [
                File::get($stub . '/Index.vue.stub'),
                File::get($stub . '/Edit.vue.stub'),
                File::get($stub . '/Create.vue.stub'),
                File::get($stub . '/Show.vue.stub'),
            ]);

            [$indexVueFile, $editVueFile, $createVueFile, $showVueFile] = $this->replaceWithData([
                '{{Models}}' => ucfirst($model),
                '{{Model}}' => ucfirst(Str::singular($model)),
                '{{models}}' => strtolower($model),
                '{{model}}' => strtolower(Str::singular($model)),
                '{{primaryKey}}' => $primaryKey[1],
            ], [$indexVueFile, $editVueFile, $createVueFile, $showVueFile]);

            if (!File::exists($path)) {
                File::makeDirectory($path);
            }

            $jsModelPath = $path . '/' . Str::studly($model);

            if (!File::exists($jsModelPath)) {
                File::makeDirectory($jsModelPath);
            }

            File::put($jsModelPath . '/Index.vue', $indexVueFile);
            File::put($jsModelPath . '/Create.vue', $createVueFile);
            File::put($jsModelPath . '/Edit.vue', $editVueFile);
            File::put($jsModelPath . '/Show.vue', $showVueFile);
        });
    }

    private function replaceWithData(array $keysAndValues, array $files): array
    {
        return collect($files)->map(function ($item) use ($keysAndValues) {
            return strtr($item, $keysAndValues);
        })->toArray();
    }

    private function buildFields(Collection $fields): Collection
    {
        return $fields->map(function ($item) {
            return [
                '{{fields-head}}' => '<th class="px-6 pt-6 pb-4">' . ucfirst($item[1]) . '</th>',
                '{{fields-data}}' => '
            <td class="border-t">
              <span class="px-4 py-2 flex items-center" tabindex="-1">
                {{ {{model}}.' . $item[1] . ' }}
              </span>
            </td>',
                '{{fields-show-data}}' => '
            <td class="border-t">
                {{ {{model}}.' . $item[1] . ' }}
            </td>',
                '{{fields-view-data}}' => '
            <div class="table-row hover:bg-grey-lightest focus-within:bg-grey-lightest">
                <div class="px-6 py-2 table-cell text-right border-r border-t"><b>'. ucfirst($item[1]).'</b></div>
                <div class="table-cell px-2 border-t w-full">{{ {{model}}.' . $item[1] . ' }}</div>
            </div>',
                '{{input-fields}}' => '
                <text-input v-model="form.' . $item[1] . '" :errors="$page.errors.' . $item[1] . '" class="pr-6 pb-8 w-full lg:w-1/2" label="'.$item[1].'"/>',
                '{{data-form-input}}' => $item[1] . ': this.{{model}}.' . $item[1] . ',',
                '{{data-form-input-null}}' => $item[1] . ': null,'
            ];
        });
    }

    private function findModels(?string $model): Collection
    {
        $files = collect(File::files(app_path()));

        return empty($model)
            ? $files
            : $files->filter(function ($file) use ($model) {
                return $file->getFilenameWithoutExtension() === $model;
            })->values();
    }

    private function matchModelsWithMigrations(Collection $models): Collection
    {
        $allMigrations = collect(File::allFiles(database_path('migrations/')));

        return $allMigrations->filter(function ($file) use ($models) {
            $model = $this->migrationToModel($file->getFilenameWithoutExtension());

            return collect($models->map->getFilenameWithoutExtension())->contains($model);
        })->values();
    }

    private function migrationToModel(string $migration): string
    {
        return Str::studly(
            Str::singular(
                Str::after(
                    Str::before(
                        $migration,
                        '_table'
                    ),
                    '_create_'
                )
            )
        );
    }
}