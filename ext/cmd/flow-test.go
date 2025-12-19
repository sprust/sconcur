package main

import (
	"fmt"
	"log"
	"sconcur/internal/dto"
	"sconcur/internal/features"
	"sconcur/internal/types"
	"time"
)

var handler *features.Handler

func main() {
	// Initialize handler
	handler = features.NewHandler()
	defer handler.Destroy()

	// Test flow 1: Sleep feature
	fmt.Println("=== Test Flow 1: Sleep Feature ===")
	testSleepFlow()

	// Test flow 2: Multiple tasks in same flow
	fmt.Println("\n=== Test Flow 2: Multiple Tasks ===")
	testMultipleTasks()

	// Test flow 3: Cancel task
	fmt.Println("\n=== Test Flow 3: Cancel Task ===")
	testCancelTask()

	// Test flow 4: Stop flow
	fmt.Println("\n=== Test Flow 4: Stop Flow ===")
	testStopFlow()

	// Test flow 5: Stop flow
	fmt.Println("\n=== Test Flow 5: Stop Flow ===")
	testStopFlow()

	// Show final task count
	fmt.Printf("\nFinal task count: %d\n", handler.GetTasksCount())
}

func testSleepFlow() {
	flowKey := "flow1"
	taskKey := "task1"

	// Push a sleep task
	msg := &dto.Message{
		FlowKey: flowKey,
		Method:  types.Method(1), // Sleep feature
		TaskKey: taskKey,
		Payload: `{"duration": 100}`,
	}

	err := handler.Push(msg)
	if err != nil {
		log.Printf("Error pushing task: %v\n", err)
		return
	}

	fmt.Printf("Pushed task: %s to flow: %s\n", taskKey, flowKey)

	// Wait for result
	result, err := handler.Wait(flowKey, 5000)
	if err != nil {
		log.Printf("Error waiting for result: %v\n", err)
		return
	}

	fmt.Printf("Result: %s\n", result)
	fmt.Printf("Task count: %d\n", handler.GetTasksCount())
}

func testMultipleTasks() {
	flowKey := "flow2"

	// Push multiple tasks
	for i := 1; i <= 3; i++ {
		taskKey := fmt.Sprintf("task%d", i)
		msg := &dto.Message{
			FlowKey: flowKey,
			Method:  types.Method(1),
			TaskKey: taskKey,
			Payload: fmt.Sprintf(`{"duration": %d}`, i*50),
		}

		err := handler.Push(msg)
		if err != nil {
			log.Printf("Error pushing task %s: %v\n", taskKey, err)
			continue
		}

		fmt.Printf("Pushed task: %s\n", taskKey)
	}

	// Wait for each result
	for i := 1; i <= 3; i++ {
		result, err := handler.Wait(flowKey, 5000)
		if err != nil {
			log.Printf("Error waiting for result %d: %v\n", i, err)
			continue
		}

		fmt.Printf("Result %d: %s\n", i, result)
	}

	fmt.Printf("Task count: %d\n", handler.GetTasksCount())
}

func testCancelTask() {
	flowKey := "flow3"
	taskKey := "task_cancel"

	msg := &dto.Message{
		FlowKey: flowKey,
		Method:  types.Method(1),
		TaskKey: taskKey,
		Payload: `{"duration": 2000}`,
	}

	err := handler.Push(msg)
	if err != nil {
		log.Printf("Error pushing task: %v\n", err)
		return
	}

	fmt.Printf("Pushed task: %s\n", taskKey)

	// Cancel task after short delay
	time.Sleep(100 * time.Millisecond)
	handler.CancelTask(flowKey, taskKey)
	fmt.Printf("Cancelled task: %s\n", taskKey)

	// Try to wait (should fail or timeout quickly)
	result, err := handler.Wait(flowKey, 1000)
	if err != nil {
		fmt.Printf("Expected error after cancel: %v\n", err)
	} else {
		fmt.Printf("Unexpected result: %s\n", result)
	}

	fmt.Printf("Task count: %d\n", handler.GetTasksCount())
}

func testStopFlow() {
	flowKey := "flow4"

	msg := &dto.Message{
		FlowKey: flowKey,
		Method:  types.Method(1),
		TaskKey: "task_stop",
		Payload: `{"duration": 2000}`,
	}

	err := handler.Push(msg)
	if err != nil {
		log.Printf("Error pushing task: %v\n", err)
		return
	}

	fmt.Printf("Pushed task to flow: %s\n", flowKey)

	// Stop flow after short delay
	time.Sleep(100 * time.Millisecond)
	handler.StopFlow(flowKey)
	fmt.Printf("Stopped flow: %s\n", flowKey)

	// Try to wait (should fail)
	result, err := handler.Wait(flowKey, 1000)
	if err != nil {
		fmt.Printf("Expected error after stop: %v\n", err)
	} else {
		fmt.Printf("Unexpected result: %s\n", result)
	}

	fmt.Printf("Task count: %d\n", handler.GetTasksCount())
}
