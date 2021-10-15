import React from 'react'
import NoContent from '../../Elements/NoContent'

const EmptyData = (props) => {
  return (
    <>
      <i className="material-icons md-48">airplane_ticket</i>
      <div>There are no available {props.content}</div>
    </>
  )
}

const Cargo = (props) => {
  return (
    <div className="shadow rounded p-4 mt-2 mr-2 bg-white">
      <div className="mb-2 text-xl">Select Cargo</div>
      <div className="my-2">
        <label htmlFor="deadHead" className="inline-flex items-center">
          <input id="deadHead" checked={props.deadHead} onChange={props.handleDeadHead} type="checkbox" className="form-checkbox rounded border-gray-300 text-orange-500 shadow-sm focus:border-orange-300 focus:ring focus:ring-offset-0 focus:ring-orange-200 focus:ring-opacity-50" />
          <span className="text-gray-700 ml-2">Deadhead - Run empty</span>
        </label>
      </div>
      {props.cargo.length === 0
        ? <NoContent content={<EmptyData content="Cargo" />} />
        : (
            !props.deadHead && (
            <div className="overflow-x-auto">
              <table className="table table-auto table-condensed">
                <thead>
                <tr>
                  <td></td>
                  <th>Split</th>
                  {/* <td></td> */}
                  <th>Contract</th>
                  <th>Current</th>
                  <th>Arrival</th>
                  <th>Distance</th>
                  <th>Heading</th>
                  <th>Type</th>
                  <th>Cargo</th>
                </tr>
                </thead>
                <tbody>
                {props.cargo.map((detail) => (
                  <tr key={detail.id} className={props.selectedCargo.some(s => s.id === detail.id) ? 'bg-orange-200 hover:bg-orange-100' : ''}>
                    <td><input id="sel" checked={props.selectedCargo.some(s => s.id === detail.id)} onChange={() => props.handleCargoSelect(detail)} type="checkbox" className="form-checkbox rounded border-gray-300 text-orange-500 shadow-sm focus:border-orange-300 focus:ring focus:ring-offset-0 focus:ring-orange-200 focus:ring-opacity-50" /></td>
                    {/* <td><button onClick={() => toggleShowSplit} className="btn btn-secondary btn-small">Split</button></td> */}
                    <td><button className="btn btn-secondary btn-small" onClick={() => props.splitCargo(detail)}>Split</button></td>
                    <td>{detail.id}</td>
                    <td>{detail.current_airport_id}</td>
                    <td>{detail.contract.arr_airport_id}</td>
                    <td>{detail.contract.distance}</td>
                    <td>{detail.contract.heading}</td>
                    <td>{detail.contract_type_id === 1 ? 'Cargo' : 'Passenger'}</td>
                    <td>
                      {detail.contract_type_id === 1
                        ? <div><span>{detail.cargo_qty} lbs</span> <span className="text-xs">{detail.cargo}</span></div>
                        : <div><span>{detail.cargo_qty}</span> <span className="text-xs">{detail.cargo}</span></div>
                      }
                    </td>
                  </tr>
                ))}
                </tbody>
              </table>
            </div>
            )
          )
      }
    </div>
  )
}

export default Cargo
