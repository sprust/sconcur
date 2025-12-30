package stateful_feature

import (
	"encoding/json"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/mongodb/objects"
	"sconcur/internal/features/mongodb/stateful/aggregate_stateful"
	"sconcur/internal/tasks"
	"sync"
)

var _ contracts.FeatureContract = (*CollectionStatefulFeature)(nil)

var once sync.Once
var instance *CollectionStatefulFeature

var errFactory = errs.NewErrorsFactory("mongodb: stateful: ")

type CollectionStatefulFeature struct {
}

func GetCollectionStatefulFeature() *CollectionStatefulFeature {
	once.Do(func() {
		instance = &CollectionStatefulFeature{}
	})

	return instance
}

func (c *CollectionStatefulFeature) Handle(task *tasks.Task) {
	var statefulParams dto.Stateful

	message := task.GetMessage()

	err := json.Unmarshal([]byte(message.Payload), &statefulParams)

	if err != nil {
		task.AddResult(
			dto.NewErrorResult(
				message,
				errFactory.ByErr("parse stateful payload error", err),
			),
		)

		return
	}

	var params objects.StatefulNextParams

	err = json.Unmarshal([]byte(statefulParams.Payload), &params)

	if err != nil {
		task.AddResult(
			dto.NewErrorResult(
				message,
				errFactory.ByErr("parse payload error", err),
			),
		)

		return
	}

	switch {
	case params.Command == 3:
		c.handleAggregate(statefulParams.TaskKey, task)
	default:
		task.AddResult(
			dto.NewErrorResult(
				message,
				errFactory.ByText("unknown command"),
			),
		)
	}
}

func (c *CollectionStatefulFeature) handleAggregate(statefulTaskKey string, task *tasks.Task) {
	message := task.GetMessage()

	states := aggregate_stateful.GetAggregates()

	state := states.GetState(statefulTaskKey)

	if state == nil {
		task.AddResult(
			dto.NewErrorResult(
				message,
				errFactory.ByText("aggregate not started"),
			),
		)

		return
	}

	result := state.Next()

	if !result.HasNext {
		states.DeleteState(statefulTaskKey)
	}

	// TODO: do pretty
	result.TaskKey = message.TaskKey

	task.AddResult(result)
}
