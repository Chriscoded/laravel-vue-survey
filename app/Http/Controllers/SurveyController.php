<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Http\Requests\StoreSurveyRequest;
use App\Http\Requests\UpdateSurveyRequest;
use Illuminate\Http\Request;
use App\Http\Resources\SurveyResource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Models\SurveyQuestion;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Arr;

// use App\Enums\QuestionTypeEnum;
// use Illuminate\Validation\Rules\Enum;

class SurveyController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        //get current user from the request
        $user = $request->user();
        //get surveys of the user
        return SurveyResource::collection(Survey::where('user_id', $user->id)->paginate(6));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreSurveyRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreSurveyRequest $request)
    {
        $data = $request->validated();

        //check if image was given and save on local file system
        if(isset($data['image'])){
            $relativePath = $this->saveImage($data['image']);
            $data['image'] = $relativePath;

        }

        $survey = Survey::create($data);

        //Create new questions
        foreach($data['questions'] as $question){
            $question['survey_id'] = $survey->id;
            $this->createQuestion($question);
        }

        return new SurveyResource($survey);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Survey  $survey
     * @return \Illuminate\Http\Response
     */
    public function show(Survey $survey, Request $request)
    {
        $user = $request->user();

        //If it is not the user that owns the survey then abort
        if($user->id !== $survey->user_id){
            return abort(403, 'Unauthorized action');
        }

        return new SurveyResource($survey);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateSurveyRequest  $request
     * @param  \App\Models\Survey  $survey
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateSurveyRequest $request, Survey $survey)
    {
        $data = $request->validated();


        //If there is an old image, and user want to upload new image delete it
        if($survey->image && isset($data['image']) ){
            $absolutePath = public_path($survey->image);
            File::delete($absolutePath);
        }
        //if there is only an old image, re-upload to database
        elseif($survey->image){
            //if user do not want to upload new image assign axisting image
            $data['image'] = $survey->image;
        }
        //else it is only a new image
        //check if new image was given and save on local file system
        elseif(isset($data['image'])){

            $relativePath = $this->saveImage($data['image']);
            $data['image'] = $relativePath;

        }

        //update survey in the database
        $survey->update($data);


        // logger($survey->surveyQuestions);
        //Get ids as plain array of existing questions
        $existingIds = $survey->surveyQuestions->pluck('id')->toArray();

        //Get ids as plain array of new questions
        $newIds = Arr::pluck($data['questions'], 'id');

        //find questions to delete
        $toDelete = array_diff($existingIds, $newIds);

        //Find questions to add
        $toAdd = array_diff($newIds, $existingIds);

        //Delete questions by $toDelete array
        SurveyQuestion::destroy($toDelete);

        //Create new questions
        foreach($data['questions'] as $question){
            if(in_array($question['id'], $toAdd)){
                $question['survey_id'] = $survey->id;
                $this->createQuestion($question);

            }
        }

        //Update existing questions
        $questionMap = collect($data['questions'])->keyBy('id');
        foreach($survey->surveyQuestions as $question){
            logger($question);
            if(isset($questionMap[$question->id])){
                $this->updateQuestion($question, $questionMap[$question->id]);
            }
        }
        return new SurveyResource($survey);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Survey  $survey
     * @return \Illuminate\Http\Response
     */
    public function destroy(Survey $survey, Request $request)
    {
        $user = $request->user();
          //If it is not the user that owns the survey then abort
        if($user->id !== $survey->user_id){
            return abort(403, "Unauthorized action");
        }

        $survey->delete();

        //If there is an image delete it from the public image folder
        if($survey->image ){
            $absolutePath = public_path($survey->image);
            File::delete($absolutePath);
        }

        return response('', 204);
    }

    //Private function to save image
    private function saveImage($image){
        // Check if image is valid base64 string

        if(preg_match('/^data:image\/(\w+);base64,/', $image, $type)){
            //Take out the base64 encoded text without mime type
            $image = substr($image, strpos($image,',') + 1 );

            //Get file extension
            //since the extension occurs after data:image
            $type = strtolower($type[1]); //jpg, png, gif

            //check if file is on image
            if(!in_array($type, ['jpg', 'jpeg', 'gif', 'png'])){
                throw new \Exception('Invalid image type');
            }
            $image = str_replace(' ', '+', $image);

            $image = base64_decode($image);

            if($image === false){
                throw new \Exception('base64_decode failed');
            }
        }
        else{
            throw new \Exception('Dis not match data URI with image data');
        }

        $dir = 'images/';
        $file = Str::random().'.'.$type;
        $absolutePath = public_path($dir);
        $relativePath = $dir.$file;

        if(!File::exists($absolutePath)){
            File::makeDirectory($absolutePath, 0755, true);
        }

        file_put_contents($relativePath, $image);

        return $relativePath;
    }

    private function createQuestion($data){
        //if their is a content it will be in data
        logger($data);
        if(is_array($data['data'])){
            $data['data'] = json_encode($data['data']);
        }

        $validator = Validator::make($data, [
            'question' => 'required|string',
            'type'     => ['required', Rule::in([
                    Survey::TYPE_TEXT,
                    Survey::TYPE_TEXTAREA,
                    Survey::TYPE_SELECT,
                    Survey::TYPE_RADIO,
                    Survey::TYPE_CHECKBOX ,
                    // new Enum(QuestionTypeEnum::class)
            ])],
            'description' => 'nullable|string',
            'data' => 'present',
            'survey_id' => 'exists:surveys,id'
        ]);

        // if ($validator->fails()) {
        //     return redirect('post/create')
        //                 ->withErrors($validator)
        //                 ->withInput();
        // }
        return SurveyQuestion::create($validator->validated());
    }

    private function updateQuestion(SurveyQuestion $question, $data){
        logger($data);
        if(is_array($data['data'])){
            $data['data'] = json_encode($data['data']);
        }

        $validator = Validator::make($data, [
            'id' => 'exists:survey_questions,id',
            'question' => 'required|string',
            'type'  =>  ['required', Rule::in([
                Survey::TYPE_TEXT,
                Survey::TYPE_TEXTAREA,
                Survey::TYPE_SELECT,
                Survey::TYPE_RADIO,
                Survey::TYPE_CHECKBOX ,
                // new Enum(QuestionTypeEnum::class)
            ])],
            'description' => 'nullable|string',
            'data' => 'present',
            // 'survey_id' => 'exists:surveys,id'

        ]);

        return $question->update($validator->validated());
    }
}
