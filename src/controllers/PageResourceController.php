<?php namespace Kjamesy\Cms\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Kjamesy\Cms\Helpers\Miscellaneous;
use kJamesy\Cms\Helpers\PagesHelper;
use Kjamesy\Cms\Models\CmsLocale;
use Kjamesy\Cms\Models\CmsMeta;
use Kjamesy\Cms\Models\Page;
use Kjamesy\Cms\Models\PageTranslation;
use Kjamesy\Utility\Facades\Utility;
use Sentinel\Repositories\User\SentinelUserRepositoryInterface;

class PageResourceController extends \BaseController {
    public function __construct(SentinelUserRepositoryInterface $userRepository)
    {
        $this->user = $userRepository->retrieveById(Session::get('userId'));
        $this->rules = Page::$rules;
    }

    public function index()
    {
        $pages = Page::getPageResource();

        $backendPages = new PagesHelper($pages);
        $pagesTree = $backendPages->getPagesTree();

        $locales = CmsLocale::getLocaleResource();

        return Response::json(compact('pagesTree', 'locales'));
    }


    public function store()
    {
        $inputs = [];
        foreach( Input::all() as $key => $input ) {
            if ( ! is_array($input) )
                $inputs[$key] = trim($input);
        }

        $validation = Miscellaneous::validate($inputs, $this->rules);

        if( $validation !== true )
            return Response::json(['validation' => $validation]);
        else
        {
            $existingSlugs = Page::lists('slug');

            if ( Str::length( Str::slug($inputs['slug']) ) ) {
               $slug = Utility::makeUniqueSlug($existingSlugs, Str::slug($inputs['slug']));
            }

            else {
                $slug = Utility::makeUniqueSlug($existingSlugs, Str::slug($inputs['title']));
            }

            $order = $inputs['order'];

            if ( strlen($order) == 0 )
                $order = 0;

            $pageArr = [
                'user_id' => $this->user->id,
                'title' => $inputs['title'],
                'slug' => $slug,
                'summary' => $inputs['summary'],
                'content' => $inputs['content'],
                'order' => $order,
                'is_online' => $inputs['is_online']
            ];

            $page = Page::create($pageArr);

            if ( $inputs['parent_id'] != 0 ) {
                $parent = Page::find( $inputs['parent_id'] );
                $page->makeChildOf($parent);
            }

            Page::rebuild(true); Cache::flush();

            return Response::json(['success' => 'Page successfully saved', 'id' => $page->id]);
        }
    }

    public function show($id)
    {
        $page = Page::getSinglePageResource($id);
        $parents = Page::getParentOptions( $page->id );
        $locales = CmsLocale::getLocaleResource();
        $metaKeys = CmsMeta::getPageMetaKeysOptionsList($page->id);

        return Response::json(compact('page', 'parents', 'locales', 'metaKeys'));
    }


    public function update($id)
    {
        $inputs = [];
        foreach( Input::get('page') as $key => $input ) {
            if ( ! is_array($input) )
                $inputs[$key] = trim($input);
        }

        $validation = Miscellaneous::validate($inputs, $this->rules);


        if( $validation !== true )
            return Response::json(['validation' => $validation]);
        else
        {
            $oldPage = Page::find($id);
            $order = $inputs['order'];

            if( strlen($order) == 0 )
                $order = 0;

            /*Update All Translations Order If The Page Order Has Changed*/
            if ( $oldPage->order != $order ) {
                $translations = PageTranslation::findAllTranslations($oldPage->id);
                foreach( $translations as $translation) {
                    $translation->order = $order;
                    $translation->save();
                }
            }

            $slug = $oldPage->slug;
            $existingSlugs = Page::lists('slug');

            if ( $inputs['slug'] == $oldPage->slug ) {
                if ( $oldPage->title != $inputs['title'] ) {
                    $slug = Utility::makeUniqueSlug($existingSlugs, Str::slug($inputs['title']));
                }
            }
            elseif ( $inputs['slug'] != $oldPage->slug && Str::length( Str::slug($inputs['slug']) ) ) {
                $slug = Utility::makeUniqueSlug($existingSlugs, Str::slug($inputs['slug']));
            }

            $oldPage->user_id = $this->user->id;
            $oldPage->title = $inputs['title'];
            $oldPage->slug = $slug;
            $oldPage->summary = $inputs['summary'];
            $oldPage->content = $inputs['content'];
            $oldPage->order = $order;
            $oldPage->is_online = $inputs['is_online'];
            $oldPage->save();


            if ( $inputs['parent_id'] != 0 && $inputs['parent_id'] != $oldPage->parent_id ) {
                $parent = Page::find($inputs['parent_id']);
                $oldPage->makeChildOf($parent);
            }

            if( $inputs['parent_id'] == 0 && $oldPage->parent_id != NULL ) {
                $oldPage->makeRoot();
            }

            /*And now let's deal with the Custom Fields. Delete anything not in the input and modify the stored values of those in the input */
            $cFieldIds = [];

            foreach ( Input::get('customFields') as $cField ) {

                $cFieldIds[] = $cField['id'];

                $meta = CmsMeta::wherePageId($id)->find($cField['id']);
                if ( $meta ) {
                    $meta->meta_value = $cField['meta_value'];
                    $meta->save();
                }
            }

            if ( count($cFieldIds) )
                CmsMeta::wherePageId($id)->whereNotIn('id', $cFieldIds)->delete();
            else
                CmsMeta::wherePageId($id)->delete();

            Cache::flush();
            return Response::json(['success' => 'Page successfully updated', 'slug' => $slug]);
        }
    }

}
